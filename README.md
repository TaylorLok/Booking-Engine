# Accommodation Booking Engine — MVP

A full-stack accommodation booking platform built with Laravel (API) and React/Next.js (frontend). Users move through a fluid multi-step flow — dates, rooms & guests, summary, submission, and confirmation — with the ability to authenticate at any point without losing their progress.

---

## Core User Journey

```
Dates Selection → Rooms & Guests → Summary → Submission → Confirmation Status
```

- Users may navigate freely between all steps as a guest.
- Authentication (login or register) can happen at **any stage**.
- Submitting a booking **requires authentication**. If a guest reaches the Summary step, an auth modal is presented inline — booking state is preserved throughout.

---

## Discussion Points

### 1. Tech Stack

**Frontend**

| Layer | Choice | Reason |
|---|---|---|
| Framework | Next.js 14 (App Router) | ISR for property data, file-based routing, SSR where needed |
| Language | TypeScript | End-to-end type safety across components and API calls |
| UI | React + Tailwind CSS | Component model suits the multi-step flow |
| Global state | Zustand + `persist` middleware | Minimal boilerplate, surgical re-renders via selectors, sessionStorage persistence |
| Server state | TanStack Query (React Query) | Polling for booking confirmation status, cache invalidation on auth change |
| Validation | Zod | Runtime schema validation on all form inputs before they reach the API |
| Forms | React Hook Form | Controlled inputs with Zod resolver |

**Backend**

| Layer | Choice | Reason |
|---|---|---|
| Framework | Laravel 13 (API mode) | Sanctum SPA auth, Form Requests, Eloquent, queue system — all built in |
| Language | PHP 8.3 | Named arguments, readonly properties, fibers |
| Database | PostgreSQL | `SELECT FOR UPDATE` for concurrency, stored computed columns, strong JSON support |
| Cache / Queue | Redis | Property JSON cache, idempotency key store, queue backend for Horizon |
| Queue runner | Laravel Horizon | Real-time queue monitoring, named queue priorities |
| Auth | Laravel Sanctum | SPA cookie-based auth — no token management on the frontend |

**Infrastructure**

- Backend on a **VPS or managed server** (e.g. Forge-managed) — PHP-FPM + Nginx
- Redis for cache, sessions, and queues
- PostgreSQL as primary datastore

---

### 2. Authentication & State

**Authentication — Laravel Sanctum (SPA mode)**

Sanctum issues a session cookie after login. Because the Next.js frontend and Laravel API share a top-level domain (e.g. `app.example.com` / `api.example.com`), the cookie is sent automatically on every request. No token storage, no `Authorization` header management, no XSS surface from storing tokens in localStorage.

Flow:
1. On app load, React Query calls `GET /api/me` — if the cookie is valid, the user object is returned and stored in Zustand's auth slice.
2. If the call returns `401`, the user is treated as a guest.
3. On login or register (at any step), Sanctum sets the cookie, React Query invalidates and re-fetches `/api/me`, and Zustand's auth state updates — the booking flow step the user was on is unchanged.

**Frontend State — Zustand with `persist`**

All booking form state (selected dates, rooms, guest counts, idempotency key) lives in a single Zustand store. The `persist` middleware serialises the booking slice to `sessionStorage` on every write and rehydrates it on page load.

```typescript
// store/bookingStore.ts (simplified)
export const useBookingStore = create<BookingState>()(
  persist(
    (set, get) => ({
      step: 1,
      checkIn: null,
      checkOut: null,
      selectedRooms: [],
      guests: 1,
      idempotencyKey: null,

      // Auth state — excluded from persist (never touches sessionStorage)
      user: null,
      isAuthenticated: false,
      authModalOpen: false,

      setDates: (checkIn, checkOut) => set({ checkIn, checkOut }),
      nextStep: () => set((s) => ({ step: Math.min(s.step + 1, 5) })),
      setUser:  (user) => set({ user, isAuthenticated: true, authModalOpen: false }),
      logout:   () => set({ user: null, isAuthenticated: false }),
      // logout does NOT reset booking state — intentional
    }),
    {
      name: 'booking-state',
      storage: createJSONStorage(() => sessionStorage),
      // partialize excludes auth — only booking fields are persisted
      partialize: (state) => ({
        step:          state.step,
        checkIn:       state.checkIn,
        checkOut:      state.checkOut,
        selectedRooms: state.selectedRooms,
        guests:        state.guests,
        idempotencyKey: state.idempotencyKey,
      }),
    }
  )
)
```

**Why `sessionStorage` over `localStorage`?**

`sessionStorage` is scoped to the browser tab and cleared when the tab closes. This prevents stale booking data from a previous session appearing when a user opens a fresh tab. Auth state is never persisted at all — it is always derived from the live Sanctum cookie.

**Components subscribe to slices, not the whole store:**

```typescript
// Only re-renders when `step` changes — not on every auth or date update
const step     = useBookingStore((s) => s.step)
const nextStep = useBookingStore((s) => s.nextStep)
```

**Auth gate at Summary step:**

```typescript
const handleSubmit = () => {
  if (!isAuthenticated) {
    openAuthModal()   // shows modal, preserves all state
    return
  }
  // proceed to API submission
}
```

---

### 3. Data Delivery — Static Property JSON

The property data (rooms, amenities, images, policies) comes from a static JSON file that rarely changes. Hitting the database for this on every request is unnecessary overhead.

**Strategy: three-layer cache**

**Layer 1 — Next.js ISR (Incremental Static Regeneration)**

The property page is statically generated at build time and regenerated in the background at most once per hour:

```typescript
// app/property/page.tsx
export const revalidate = 3600 // regenerate at most every 1 hour

export default async function PropertyPage() {
  const property = await fetch(`${process.env.API_URL}/api/property`, {
    next: { revalidate: 3600 },
  }).then(r => r.json())

  return <PropertyLayout data={property} />
}
```

Visitors receive a statically served HTML page from Vercel's CDN — zero API calls on page load for the majority of traffic.

**Layer 2 — Redis cache on the API**

When the Next.js background revalidation does call the API, Laravel returns the property data from Redis rather than the filesystem:

```php
// PropertyService.php
public function getData(): array
{
    return Cache::remember('property.data', 3600, function () {
        $path = storage_path('property.json');
        return json_decode(file_get_contents($path), true);
    });
}
```

**Layer 3 — HTTP cache headers**

The API endpoint also sets `Cache-Control: public, max-age=3600, stale-while-revalidate=86400` so that any intermediate CDN or reverse proxy can cache the response without hitting Laravel.

**On-demand revalidation**

If the property JSON is updated (new room added, price change), an admin action calls `php artisan property:sync` (which clears the Redis key) and triggers Next.js on-demand revalidation via `revalidatePath('/property')`.

---

### 4. Database & Concurrency

**Schema overview**

```
users
  id, firstname, surname, cellphone, email, password, phone, timestamps, soft_deletes

rooms
  id, slug (unique), name, type, max_adults, max_children,
  max_occupancy (computed), price_per_night_cents, is_active,
  total_units, timestamps

bookings
  id, reference (unique), idempotency_key (unique), user_id (FK),
  status (pending|confirmed|failed|cancelled),
  check_in, check_out, adults, children,
  subtotal_cents, taxes_cents, total_cents,
  special_requests, external_reference,
  failure_reason, api_attempt_count,
  confirmed_at, cancelled_at, timestamps, soft_deletes

booking_rooms
  id, booking_id (FK), room_id (FK),
  adults, children,
  price_per_night_cents,  ← snapshot, frozen at booking time
  nights_count,           ← denormalised
  line_total_cents        ← computed (price × nights)

room_holds
  id, room_id (FK), booking_id (FK),
  check_in, check_out, expires_at, timestamps

booking_status_events     ← append-only audit log
  id, booking_id (FK), from_status, to_status,
  triggered_by, metadata, created_at
```

**All monetary values are stored in cents (integers).** `1 200 ZAR = 120000`. No floating-point arithmetic on money.

**Concurrency — preventing double-booking**

Two users can simultaneously pass the availability check for the same room. Without a concurrency control, both bookings would be confirmed. We handle this at two levels:

**Level 1 — Database transaction with `SELECT FOR UPDATE`**

When `ProcessBookingJob` runs, it wraps the availability check and hold insertion in a transaction with a row-level lock:

```php
DB::transaction(function () use ($booking) {
    // Lock rows for this room in the target date range
    $existingHolds = RoomHold::where('room_id', $booking->room_id)
        ->where('expires_at', '>', now())
        ->whereDateRangeOverlaps($booking->check_in, $booking->check_out)
        ->lockForUpdate()   // SELECT FOR UPDATE
        ->get();

    $confirmedCount = BookingRoom::whereHas('booking', fn($q) =>
            $q->where('status', 'confirmed'))
        ->where('room_id', $booking->room_id)
        ->whereDateRangeOverlaps($booking->check_in, $booking->check_out)
        ->count();

    $available = $room->total_units - $confirmedCount - $existingHolds->count();

    if ($available < 1) {
        $booking->update(['status' => 'failed', 'failure_reason' => 'Room no longer available']);
        return;
    }

    // Safe to place hold — no concurrent job can pass this block simultaneously
    RoomHold::create([
        'room_id'    => $booking->room_id,
        'booking_id' => $booking->id,
        'check_in'   => $booking->check_in,
        'check_out'  => $booking->check_out,
        'expires_at' => now()->addMinutes(15),
    ]);
});
```

`lockForUpdate()` translates to `SELECT ... FOR UPDATE` in PostgreSQL. Any concurrent transaction trying to lock the same rows will block until this transaction commits or rolls back.

**Level 2 — Unique constraint on `idempotency_key`**

Even if the queue somehow fires the same job twice, the second `INSERT INTO bookings` will fail with a unique constraint violation. The job catches this and returns without creating a duplicate.

**Expired holds cleanup**

A scheduled job runs every 5 minutes to release holds where `expires_at < NOW()`:

```php
// App\Console\Kernel.php
$schedule->job(new ReleaseExpiredHoldsJob)->everyFiveMinutes();
```

---

### 5. Resilient Integration — Slow & Flaky External API

The external property management API is slow (sometimes 8–15 seconds) and occasionally unreliable. We must not block the user's browser on it or lose a booking if it fails temporarily.

**Fire-and-forget with async queue**

Booking submission is split into two phases:

**Phase 1 — Synchronous (fast, always succeeds)**

```
POST /api/bookings
  → Validate request (Form Request)
  → Check idempotency key — if seen before, return existing booking ref
  → Insert booking row with status = pending
  → Dispatch ProcessBookingJob to the `bookings` queue
  → Return { reference: "BK-20240815-A3F9", status: "pending" }
```

The API responds in milliseconds. The user is redirected to the confirmation page immediately.

**Phase 2 — Async (queue worker)**

`ProcessBookingJob` handles the slow external API call:

```php
class ProcessBookingJob implements ShouldQueue
{
    public int $tries   = 3;
    public int $timeout = 30;

    public function handle(): void
    {
        // 1. Place room hold (with SELECT FOR UPDATE)
        // 2. Call external API with 10s HTTP timeout
        // 3a. Success → update booking status to confirmed, delete hold, fire event
        // 3b. Failure → retry with exponential backoff
        // 3c. Final failure → status = failed, release hold, notify user
    }

    public function backoff(): array
    {
        return [30, 120, 300]; // 30s, 2min, 5min between retries
    }

    public function failed(Throwable $e): void
    {
        $this->booking->update([
            'status'         => 'failed',
            'failure_reason' => 'External API unavailable after 3 attempts',
        ]);
        // Release hold, send failure notification email
    }
}
```

**Frontend polls for status**

The confirmation page uses React Query to poll `GET /api/bookings/{ref}/status` every 2 seconds until the status is no longer `pending`:

```typescript
const { data } = useQuery({
  queryKey: ['booking-status', reference],
  queryFn:  () => fetchBookingStatus(reference),
  refetchInterval: (data) =>
    data?.status === 'pending' ? 2000 : false, // stop polling once resolved
})
```

**Circuit breaker**

A Redis counter tracks consecutive external API failures. If more than 5 failures occur within 60 seconds, new jobs are paused and an alert is raised — preventing the queue from saturating during a prolonged outage.

---

### 6. Defensive Design

**Double-click / double-submit prevention**

The submit button is disabled immediately on first click and only re-enabled if the API call returns an error:

```typescript
const [submitting, setSubmitting] = useState(false)

const handleSubmit = async () => {
  setSubmitting(true)
  try {
    await submitBooking(payload)
  } catch {
    setSubmitting(false) // re-enable only on failure
  }
}

<button disabled={submitting}>
  {submitting ? 'Processing...' : 'Confirm booking'}
</button>
```

**Idempotency key**

A UUID is generated client-side when the user first reaches the Summary step and stored in Zustand (persisted to sessionStorage). It is sent with every submission attempt. If the network drops after the API receives the request but before the client gets the response, resubmitting returns the original booking reference rather than creating a duplicate.

```typescript
// Generated once, reused on retries
generateIdempotencyKey: () => set({ idempotencyKey: crypto.randomUUID() })
```

On the API side:
```php
// BookingController.php
$existing = Booking::where('idempotency_key', $request->idempotency_key)->first();
if ($existing) {
    return BookingResource::make($existing); // idempotent — return existing record
}
```

**Server-side re-validation**

Every field the frontend sends is re-validated server-side by a Laravel Form Request. Prices, room IDs, and availability are **never trusted from the client** — the API recalculates everything from canonical server data:

```php
// ProcessBookingJob.php (price verification)
$serverTotal = collect($booking->rooms)->sum(fn($room) =>
    $room->price_per_night_cents * $booking->nights_count
);

if ($serverTotal !== $booking->subtotal_cents) {
    // Price was tampered — reject and log
    throw new PriceMismatchException();
}
```

**User mistake protection**

- `beforeunload` event warns users before navigating away mid-flow with unsaved state.
- Date validation: check-out must be after check-in, max stay enforced (e.g. 30 nights), no past dates.
- Guest count validated against `rooms.max_occupancy` server-side — not just client-side.
- CSRF protection on all state-changing routes (Sanctum handles this automatically for SPA auth).

---

### 7. Additional Improvement — Real-time Availability via WebSockets

**The problem:** If two users are on the Rooms & Guests step simultaneously and one completes their booking while the other is still browsing, the second user won't know a room has become unavailable until they hit an error at submission.

**The solution:** Push room availability changes to all connected clients in real time using **Laravel Reverb** (self-hosted WebSocket server) and **Laravel Echo** on the frontend.

This demonstrates **event-driven architecture** and **pub/sub** — neither of which is required by any of the previous points.

**How it works:**

When a `room_hold` is created or expires (or a booking is confirmed/cancelled), the backend broadcasts a `RoomAvailabilityUpdated` event on a public channel:

```php
// app/Events/RoomAvailabilityUpdated.php
class RoomAvailabilityUpdated implements ShouldBroadcast
{
    public function broadcastOn(): Channel
    {
        return new Channel('availability');
    }

    public function broadcastWith(): array
    {
        return [
            'room_id'          => $this->roomId,
            'check_in'         => $this->checkIn,
            'check_out'        => $this->checkOut,
            'units_available'  => $this->unitsAvailable,
        ];
    }
}
```

The frontend subscribes via Echo and updates the UI immediately — no polling needed:

```typescript
// hooks/useAvailability.ts
useEffect(() => {
  const channel = Echo.channel('availability')

  channel.listen('RoomAvailabilityUpdated', (data) => {
    queryClient.setQueryData(['availability', data.room_id], (prev) => ({
      ...prev,
      units_available: data.units_available,
    }))
  })

  return () => Echo.leave('availability')
}, [])
```

If a room becomes unavailable while the user is on the Rooms & Guests step, the room card updates to "Just taken" in real time — preventing the frustrating experience of hitting an error at the final submission step.

**Why this is meaningful beyond the basic requirements:**

It introduces WebSocket infrastructure (Reverb), a pub/sub event system, and client-side cache invalidation from a push event rather than a pull request. It also improves the product experience in a measurable way: fewer failed submissions, less user frustration, and a live feel to the interface.

---

## Running the migrations

```bash
# Install dependencies
composer install

# Copy environment file and configure your database + Redis connection
cp .env.example .env
php artisan key:generate

# Run all migrations in order
php artisan migrate

# Seed rooms from the property JSON
php artisan property:sync

# Start queue worker (development)
php artisan queue:work --queue=bookings,default,maintenance

# Start Horizon (production)
php artisan horizon
```

---

## Project Structure

```
booking-engine/
├── database/
│   └── migrations/
│       ├── ..._create_users_table.php
│       ├── ..._create_personal_access_tokens_table.php
│       ├── ..._create_rooms_table.php
│       ├── ..._create_bookings_table.php
│       ├── ..._create_booking_rooms_table.php
│       ├── ..._create_room_holds_table.php
│       ├── ..._create_booking_status_events_table.php
│       ├── ..._create_failed_jobs_table.php
│       ├── ..._create_jobs_table.php
│       ├── ..._create_cache_and_sessions_tables.php
│       └── ..._create_password_reset_tokens_table.php
├── app/
│   ├── Http/Controllers/
│   ├── Http/Requests/
│   ├── Models/
│   ├── Jobs/
│   ├── Events/
│   └── Services/
└── README.md
```