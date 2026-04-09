# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Tailwind CSS

The Tailwind v4 CLI watcher runs in the background during development:

```bash
bash tailwind.sh
# or manually:
npx @tailwindcss/cli -i ./src/input.css -o ./styles.css --watch
```

**Never manually rebuild** — assume the watcher is already running. `styles.css` is the compiled output; never edit it directly. All Tailwind source is in `src/input.css`. The `@source` directives tell Tailwind to scan `index.php`, `api.php`, and `app.js` for class names.

## Architecture

This is a single-page CalDAV web frontend with no framework. The three main files are:

- **`index.php`** — Auth (login, first-login setup, logout, CSRF), app HTML shell, all modal HTML. Also handles the public ICS proxy at `?c=<token>` (no auth, runs before session_start).
- **`api.php`** — All CalDAV operations behind session+CSRF guard. Actions: `calendars`, `events`, `update`, `create`, `delete`, `create-calendar`, `calendar-tokens`. Contains the full `CalDAV` PHP class.
- **`app.js`** — All UI logic: rendering list/month views, sidebar, modals (event edit/create, calendar create), ICS import, public URL display, search/filter, state management.

### Auth & config

`config.json` stores CalDAV server credentials (`caldav.url`, `caldav.username`, `caldav.password`, `caldav.calendar_home`), frontend users with bcrypt-hashed passwords, `week_start`, and `proxy_secret` (auto-generated). A user with `password_hash: null` triggers first-login password setup.

### CalDAV communication

All CalDAV requests use `CURLAUTH_ANY` (required for Baikal's Digest Auth). `curlRequest` always sets `CURLOPT_HEADER => true` and splits response into headers + body to extract ETags. Calendar discovery: `calendar_home` override in config → else `current-user-principal` → `calendar-home-set` → fallback to `/.well-known/caldav`.

### Event flow

- **Read**: REPORT (calendar-query with time-range) → `parseICalendar` → `processEvent` → `expandEvent` (RRULE expansion: DAILY/WEEKLY/MONTHLY/YEARLY, BYDAY, COUNT, UNTIL, EXDATE, up to 3000 iterations)
- **Write (update)**: GET fresh ETag → `updateICalendar` (unfold, replace only touched props, re-fold at 75 chars) → PUT with `If-Match`. Calendar move = PUT to new URL + DELETE old.
- **Write (create)**: generate UUID → build VCALENDAR from scratch → PUT with `If-None-Match: *`
- **Delete**: DELETE with `If-Match: *`
- All-day DTEND is exclusive (RFC 5545): display end-1 day, save end+1 day.

### Frontend state & rendering

`state` object holds `calendars`, `events`, `hiddenCalendars` (Set, persisted to localStorage), `view`, `currentMonth`. `render()` is the single re-render entry point. `evReg[]` is reset on each render and stores event objects by index — onclick handlers pass the index to `openEditModal(idx)`.

### Modal visibility

Modals use CSS class toggling, **not** inline `style.display`:
```css
#ev-modal         { display: none; }
#ev-modal.is-open { display: flex; }
```
Always `classList.add/remove('is-open')`. Never manipulate `style.display` on modals.

### Public ICS proxy

`?c=<token>` in `index.php` (before session_start) serves a calendar as `.ics` without auth. Token = `substr(hash_hmac('sha256', calendarHref, proxy_secret), 0, 32)`. The proxy re-discovers calendars via PROPFIND on each request to validate the token.
