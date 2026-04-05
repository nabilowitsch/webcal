'use strict';

const WEEK_START = window.__WEEK_START ?? 1; // 0=Sun, 1=Mon, …, 6=Sat

// Registry of rendered events for click handling (reset on each render)
const evReg = [];

// ── State ─────────────────────────────────────────────────────────────────────

const state = {
    calendars:       [],
    events:          [],
    hiddenCalendars: new Set(JSON.parse(localStorage.getItem('wc_hidden') || '[]')),
    view:            localStorage.getItem('wc_view') || 'list',
    currentMonth:    new Date(),
};

// Reset month to current month on init
state.currentMonth.setDate(1);
state.currentMonth.setHours(0, 0, 0, 0);

// ── API ───────────────────────────────────────────────────────────────────────

async function apiFetch(action) {
    const url = `api.php?action=${action}`;
    const res = await fetch(url, {
        headers: { 'X-CSRF-Token': window.__CSRF },
    });
    if (!res.ok) {
        const body = await res.json().catch(() => ({}));
        throw new Error(body.error || `HTTP ${res.status}`);
    }
    return res.json();
}

// ── Init ──────────────────────────────────────────────────────────────────────

async function init() {
    applyViewButtons(state.view);

    try {
        // Load calendars first so sidebar shows quickly
        const calData = await apiFetch('calendars');
        state.calendars = calData.calendars || [];
        renderSidebar();

        // Then load events
        const evData = await apiFetch('events');
        state.events = evData.events || [];
        render();
    } catch (err) {
        document.getElementById('content').innerHTML = errorBlock(err.message);
    }
}

// ── Sidebar ───────────────────────────────────────────────────────────────────

function renderSidebar() {
    const el = document.getElementById('calendar-list');

    if (state.calendars.length === 0) {
        el.innerHTML = '<div class="px-3 py-2 text-sm text-gray-400">No calendars found.</div>';
        return;
    }

    el.innerHTML = state.calendars.map(cal => {
        const hidden   = state.hiddenCalendars.has(cal.href);
        const boxStyle = hidden
            ? `border:2px solid ${cal.color};background:transparent`
            : `border:2px solid ${cal.color};background:${cal.color}`;
        const textCls  = hidden ? 'text-gray-400' : 'text-gray-700';
        const check    = hidden ? '' : `<svg viewBox="0 0 10 8" fill="none" class="w-2.5 h-2 text-white shrink-0">
                <path d="M1 3.5L3.8 6.5L9 1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>`;
        return `
        <button data-href="${esc(cal.href)}" onclick="toggleCalendar(this.dataset.href)"
                class="w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-left hover:bg-gray-50 transition-colors">
            <span class="w-4 h-4 rounded shrink-0 flex items-center justify-center transition-colors"
                  style="${boxStyle}">${check}</span>
            <span class="text-sm truncate flex-1 ${textCls}">${esc(cal.name)}</span>
        </button>`;
    }).join('');
}

function toggleCalendar(href) {
    if (state.hiddenCalendars.has(href)) {
        state.hiddenCalendars.delete(href);
    } else {
        state.hiddenCalendars.add(href);
    }
    localStorage.setItem('wc_hidden', JSON.stringify([...state.hiddenCalendars]));
    renderSidebar();
    render();
}

// ── View switching ────────────────────────────────────────────────────────────

function setView(view) {
    state.view = view;
    localStorage.setItem('wc_view', view);
    applyViewButtons(view);
    render();
}

function applyViewButtons(view) {
    const active   = 'px-3 py-1.5 text-xs font-semibold rounded-lg bg-white text-gray-800 shadow-xs';
    const inactive = 'px-3 py-1.5 text-xs font-semibold rounded-lg text-gray-500 hover:text-gray-700 transition-colors';
    const monthNav = document.getElementById('month-nav');
    const heading  = document.getElementById('view-heading');

    document.getElementById('btn-list').className  = view === 'list'  ? active : inactive;
    document.getElementById('btn-month').className = view === 'month' ? active : inactive;

    if (view === 'list') {
        monthNav.classList.add('hidden');
        monthNav.classList.remove('flex');
        heading.innerHTML = '<button onclick="openNewEventModal()" title="New event" '
            + 'class="flex items-center justify-center w-7 h-7 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-xl font-light transition-colors leading-none">+</button>';
    } else {
        monthNav.classList.remove('hidden');
        monthNav.classList.add('flex');
        heading.textContent = '';
        updateMonthLabel();
    }
}

function render() {
    evReg.length = 0; // reset click registry before each render
    if (state.view === 'list') renderList();
    else renderMonth();
}

function regEv(ev) {
    evReg.push(ev);
    return evReg.length - 1;
}

// ── Filtered events ───────────────────────────────────────────────────────────

function visibleEvents() {
    return state.events.filter(e => !state.hiddenCalendars.has(e.calendarHref));
}

// ── List view ─────────────────────────────────────────────────────────────────

function renderList() {
    const content = document.getElementById('content');

    // Only show events from today onwards
    const todayStr = localDateKey(new Date());
    const events   = visibleEvents().filter(ev => {
        const key = ev.allDay ? ev.start.slice(0, 10) : localDateKey(new Date(ev.start));
        return key >= todayStr;
    });

    if (events.length === 0) {
        content.innerHTML = emptyBlock('No upcoming events');
        return;
    }

    // Group by local date
    const groups = {};
    events.forEach(ev => {
        const key = ev.allDay ? ev.start.slice(0, 10) : localDateKey(new Date(ev.start));
        (groups[key] = groups[key] || []).push(ev);
    });

    const rows = Object.keys(groups).sort().map(key => {
        const date    = new Date(key + 'T12:00:00');
        const isToday = key === todayStr;

        const dayNum  = date.getDate();
        const month   = date.toLocaleDateString(undefined, { month: 'short' })
                            .replace(/\.$/, '').toUpperCase() + '.';
        const weekday = date.toLocaleDateString(undefined, { weekday: 'short' })
                            .replace(/\.$/, '').toUpperCase();
        const dayLabel = `${month}, ${weekday}`;

        const numCls   = isToday ? 'text-blue-600 font-semibold' : 'text-gray-800 font-light';
        const labelCls = isToday ? 'text-blue-500' : 'text-gray-400';

        const items = groups[key].map(ev => {
            const idx     = regEv(ev);
            const timeStr = ev.allDay
                ? 'Ganztägig'
                : `${fmtTime(new Date(ev.start))} – ${fmtTime(new Date(ev.end))}`;
            const loc = ev.location
                ? `<div class="text-xs text-gray-400 truncate mt-0.5 pl-5">${esc(ev.location)}</div>`
                : '';
            return `
            <div class="mb-2 last:mb-0 -mx-1 px-1 py-0.5 rounded-lg cursor-pointer hover:bg-gray-50 transition-colors"
                 onclick="openEditModal(${idx})">
                <div class="flex gap-2.5 min-w-0">
                    <span class="w-2.5 h-2.5 mt-1.5 rounded-full shrink-0" style="background-color:${ev.color}"></span>
                    <span class="text-sm text-gray-400 md:w-32 w-16 shrink-0">${esc(timeStr)}</span>
                    <span class="text-sm text-gray-900">${esc(ev.summary)}</span>
                </div>
                ${loc}
            </div>`;
        }).join('');

        return `
        <div class="flex gap-4 px-4 lg:px-6 py-4 border-b border-gray-100 last:border-0">
            <div class="flex flex-wrap gap-2 xshrink-0 text-center pt-0.5">
                <div class="text-2xl md:w-10 w-7 leading-none ${numCls}">${dayNum}</div>
                <div class="text-[10px] md:w-15 w-7 font-medium uppercase tracking-wide mt-1 ${labelCls}">${dayLabel}</div>
            </div>
            <div class="flex-1 min-w-0">${items}</div>
        </div>`;
    }).join('');

    content.innerHTML = `
    <div class="mx-auto my-4 lg:my-6 mx-4 lg:mx-6 bg-white rounded-2xl border border-gray-100 overflow-hidden">
        ${rows}
    </div>`;
}

// ── Month view ────────────────────────────────────────────────────────────────

function renderMonth() {
    const content = document.getElementById('content');
    updateMonthLabel();

    const year  = state.currentMonth.getFullYear();
    const month = state.currentMonth.getMonth();

    const firstDay  = new Date(year, month, 1);
    const lastDay   = new Date(year, month + 1, 0);
    const gridStart = new Date(firstDay);
    const startOffset = (firstDay.getDay() - WEEK_START + 7) % 7;
    gridStart.setDate(1 - startOffset);
    const gridEnd   = new Date(lastDay);
    const lastDayOfWeek = (WEEK_START + 6) % 7;
    const endOffset = (lastDayOfWeek - lastDay.getDay() + 7) % 7;
    gridEnd.setDate(lastDay.getDate() + endOffset);

    const todayStr = localDateKey(new Date());
    const events   = visibleEvents();

    // Map events to the days they occupy
    const byDay = {};
    events.forEach(ev => {
        if (ev.allDay) {
            let d       = new Date(ev.start.slice(0, 10) + 'T12:00:00');
            const eDate = new Date(ev.end.slice(0, 10)   + 'T12:00:00');
            eDate.setDate(eDate.getDate() - 1); // DTEND is exclusive
            for (let i = 0; i < 60 && d <= eDate; i++) {
                addToDay(byDay, localDateKey(d), ev);
                d.setDate(d.getDate() + 1);
            }
        } else {
            addToDay(byDay, localDateKey(new Date(ev.start)), ev);
        }
    });

    // Group days into weeks
    const weeks = [];
    const cursor = new Date(gridStart);
    while (cursor <= gridEnd) {
        const weekDays = [];
        for (let d = 0; d < 7; d++) {
            weekDays.push(new Date(cursor));
            cursor.setDate(cursor.getDate() + 1);
        }
        weeks.push({ weekNum: isoWeek(weekDays[0]), days: weekDays });
    }

    const allDayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const dayNames = [...allDayNames.slice(WEEK_START), ...allDayNames.slice(0, WEEK_START)];

    const header = `
    <div class="flex shrink-0 border-b border-gray-100">
        <div class="w-8 lg:w-10 shrink-0 border-r border-gray-100 bg-blue-50"></div>
        <div class="flex-1 grid grid-cols-7 divide-x divide-gray-100">
            ${dayNames.map(d =>
                `<div class="py-2 text-center text-[12px] font-bold text-gray-800 uppercase tracking-widest">${d}</div>`
            ).join('')}
        </div>
    </div>`;

    const weekRows = weeks.map(({ weekNum, days }) => {
        const cells = days.map(day => {
            const key       = localDateKey(day);
            const inMonth   = day.getMonth() === month;
            const isToday   = key === todayStr;
            const dayEvs    = byDay[key] || [];
            const shown     = dayEvs.slice(0, 4);
            const overflow  = dayEvs.length - shown.length;

            const numEl = isToday
                ? `<span class="w-6 h-6 rounded-full bg-blue-600 text-white text-xs font-semibold flex items-center justify-center">${day.getDate()}</span>`
                : `<span class="text-sm ${inMonth ? 'text-gray-700' : 'text-gray-300'}">${day.getDate()}</span>`;

            const evHtml = shown.map(ev => {
                const idx = regEv(ev);
                if (ev.allDay) {
                    return `
                    <div class="text-[10px] leading-4 text-white font-medium px-1.5 rounded truncate mt-0.5 cursor-pointer hover:opacity-80 transition-opacity"
                         style="background-color:${ev.color}"
                         title="${esc(ev.summary)}"
                         onclick="event.stopPropagation();openEditModal(${idx})">${esc(ev.summary)}</div>`;
                }
                return `
                <div class="flex items-center gap-1 mt-0.5 min-w-0 cursor-pointer hover:bg-gray-100 rounded transition-colors"
                     title="${esc(ev.summary)}"
                     onclick="event.stopPropagation();openEditModal(${idx})">
                    <span class="w-2 h-2 rounded-full shrink-0" style="background-color:${ev.color}"></span>
                    <span class="text-[10px] text-gray-400 shrink-0 tabular-nums">${fmtTime(new Date(ev.start))}</span>
                    <span class="text-[10px] text-gray-800 truncate">${esc(ev.summary)}</span>
                </div>`;
            }).join('');

            const moreHtml = overflow > 0
                ? `<div class="text-[10px] text-gray-400 mt-0.5">+${overflow} more</div>`
                : '';

            return `
            <div class="p-1.5 overflow-hidden cursor-pointer ${inMonth ? '' : 'bg-gray-50/60'}"
                 onclick="openNewEventModal('${key}')">
                <div class="flex justify-center mb-0.5">${numEl}</div>
                ${evHtml}${moreHtml}
            </div>`;
        }).join('');

        return `
        <div class="flex flex-1">
            <div class="w-8 lg:w-10 shrink-0 flex items-start justify-center pt-2
                        text-[10px] font-medium text-gray-600 border-r border-gray-100 bg-blue-50">
                ${weekNum}
            </div>
            <div class="flex-1 grid grid-cols-7 divide-x divide-gray-100">${cells}</div>
        </div>`;
    }).join('');

    content.innerHTML = `
    <div class="h-full flex flex-col p-4 lg:p-6">
        <div class="bg-white rounded-2xl border border-gray-100 overflow-hidden flex flex-col flex-1">
            ${header}
            <div class="flex flex-col flex-1 divide-y divide-gray-100">${weekRows}</div>
        </div>
    </div>`;
}

function addToDay(map, key, ev) {
    if (!map[key]) map[key] = [];
    if (!map[key].some(e => e.uid === ev.uid && e.start === ev.start)) {
        map[key].push(ev);
    }
}

// ── Month navigation ──────────────────────────────────────────────────────────

function changeMonth(delta) {
    state.currentMonth.setMonth(state.currentMonth.getMonth() + delta);
    renderMonth();
    updateMonthLabel();
}

function updateMonthLabel() {
    const el = document.getElementById('month-label');
    if (el) {
        el.textContent = state.currentMonth.toLocaleDateString(undefined, {
            month: 'long', year: 'numeric',
        });
    }
}

// ── Sidebar toggle (mobile) ───────────────────────────────────────────────────

function openSidebar() {
    document.getElementById('sidebar').classList.remove('-translate-x-full');
    document.getElementById('backdrop').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeSidebar() {
    document.getElementById('sidebar').classList.add('-translate-x-full');
    document.getElementById('backdrop').classList.add('hidden');
    document.body.style.overflow = '';
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function isoWeek(date) {
    const d   = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
    const day = d.getUTCDay() || 7;              // Mon=1 … Sun=7
    d.setUTCDate(d.getUTCDate() + 4 - day);      // shift to Thursday of this week
    const yearStart = new Date(Date.UTC(d.getUTCFullYear(), 0, 1));
    return Math.ceil((((d - yearStart) / 864e5) + 1) / 7);
}

function localDateKey(date) {
    const y  = date.getFullYear();
    const m  = String(date.getMonth() + 1).padStart(2, '0');
    const d  = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

function fmtTime(date) {
    return date.toLocaleTimeString(undefined, {
        hour: '2-digit', minute: '2-digit',
    });
}

function formatDateHeading(key) {
    const todayStr    = localDateKey(new Date());
    const tomorrowStr = localDateKey(new Date(Date.now() + 864e5));
    if (key === todayStr)    return 'Today';
    if (key === tomorrowStr) return 'Tomorrow';
    return new Date(key + 'T12:00:00').toLocaleDateString(undefined, {
        weekday: 'long', month: 'long', day: 'numeric',
    });
}

function esc(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

function emptyBlock(msg) {
    return `
    <div class="flex flex-col items-center justify-center h-full text-center p-8">
        <svg class="w-12 h-12 text-gray-200 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1"
                  d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
        </svg>
        <p class="text-gray-400 text-sm">${esc(msg)}</p>
    </div>`;
}

function errorBlock(msg) {
    return `
    <div class="flex flex-col items-center justify-center h-full text-center p-8">
        <svg class="w-10 h-10 text-red-300 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                  d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
        </svg>
        <p class="text-gray-700 font-medium text-sm">Failed to load calendar data</p>
        <p class="text-xs text-gray-400 mt-1">${esc(msg)}</p>
        <button onclick="init()"
                class="mt-4 px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-xl hover:bg-blue-700 transition-colors">
            Retry
        </button>
    </div>`;
}

// ── Edit modal ────────────────────────────────────────────────────────────────

let _editingEvent = null;

function openNewEventModal(dateStr) {
    _editingEvent = null;

    document.getElementById('ev-summary').value     = '';
    document.getElementById('ev-location').value    = '';
    document.getElementById('ev-description').value = '';
    document.getElementById('ev-allday').checked    = false;

    const sel = document.getElementById('ev-calendar');
    sel.innerHTML = state.calendars.map((cal, i) =>
        `<option value="${esc(cal.href)}" ${i === 0 ? 'selected' : ''}>${esc(cal.name)}</option>`
    ).join('');

    const dateValue = dateStr || localDateKey(new Date());
    document.getElementById('ev-start-date').value = dateValue;
    document.getElementById('ev-end-date').value   = dateValue;
    document.getElementById('ev-start-time').value = '';
    document.getElementById('ev-end-time').value   = '';

    onAlldayChange();

    document.getElementById('ev-recurring-notice').classList.add('hidden');
    ['ev-summary','ev-calendar','ev-allday','ev-start-date','ev-start-time','ev-end-date','ev-end-time','ev-location','ev-description']
        .forEach(id => { document.getElementById(id).disabled = false; });

    document.getElementById('ev-modal-title').textContent = 'New Event';
    document.getElementById('ev-save-btn').textContent    = 'Create';
    document.getElementById('ev-save-btn').disabled       = false;

    document.getElementById('ev-modal').classList.add('is-open');
    document.getElementById('ev-summary').focus();
}

function openEditModal(idx) {
    const ev = evReg[idx];
    if (!ev) return;
    _editingEvent = ev;

    const isAllDay   = ev.allDay;
    const isRecurring = ev.isRecurring;

    document.getElementById('ev-modal-title').textContent = 'Edit Event';
    document.getElementById('ev-save-btn').textContent    = 'Save';

    // Populate fields
    document.getElementById('ev-summary').value     = ev.summary;
    document.getElementById('ev-location').value    = ev.location;
    document.getElementById('ev-description').value = ev.description;
    document.getElementById('ev-allday').checked    = isAllDay;

    // Populate calendar select
    const sel = document.getElementById('ev-calendar');
    sel.innerHTML = state.calendars.map(cal =>
        `<option value="${esc(cal.href)}" ${cal.href === ev.calendarHref ? 'selected' : ''}>${esc(cal.name)}</option>`
    ).join('');

    const start = new Date(ev.start);
    document.getElementById('ev-start-date').value = localDateKey(start);
    document.getElementById('ev-start-time').value = isAllDay ? '' : `${pad2(start.getHours())}:${pad2(start.getMinutes())}`;

    // All-day DTEND is exclusive → show last inclusive day in the form
    const end = new Date(ev.end);
    if (isAllDay) end.setUTCDate(end.getUTCDate() - 1);
    document.getElementById('ev-end-date').value = isAllDay
        ? end.toISOString().slice(0, 10)
        : localDateKey(new Date(ev.end));
    document.getElementById('ev-end-time').value = isAllDay ? '' : `${pad2(new Date(ev.end).getHours())}:${pad2(new Date(ev.end).getMinutes())}`;

    onAlldayChange();

    // Recurring: show notice, disable save
    document.getElementById('ev-recurring-notice').classList.toggle('hidden', !isRecurring);
    document.getElementById('ev-save-btn').disabled = isRecurring;

    // Disable form fields for recurring events
    ['ev-summary','ev-calendar','ev-allday','ev-start-date','ev-start-time','ev-end-date','ev-end-time','ev-location','ev-description']
        .forEach(id => { document.getElementById(id).disabled = isRecurring; });

    document.getElementById('ev-modal').classList.add('is-open');
    document.getElementById('ev-summary').focus();
}

function closeEditModal() {
    document.getElementById('ev-modal').classList.remove('is-open');
    _editingEvent = null;
}

function onAlldayChange() {
    const allDay = document.getElementById('ev-allday').checked;
    document.querySelectorAll('.ev-time').forEach(el => {
        el.style.display = allDay ? 'none' : '';
    });
}

async function saveEvent() {
    const isNew = (_editingEvent === null);
    if (!isNew && _editingEvent.isRecurring) return;

    const summary   = document.getElementById('ev-summary').value.trim();
    const allDay    = document.getElementById('ev-allday').checked;
    const startDate = document.getElementById('ev-start-date').value;
    const startTime = document.getElementById('ev-start-time').value || '00:00';
    const endDate   = document.getElementById('ev-end-date').value;
    const endTime   = document.getElementById('ev-end-time').value   || '00:00';

    if (!summary || !startDate || !endDate) {
        alert('Please fill in Title, Start, and End.');
        return;
    }

    let startUTC, endUTC;
    if (allDay) {
        startUTC = new Date(startDate + 'T00:00:00Z').toISOString();
        const endDt = new Date(endDate + 'T00:00:00Z');
        endDt.setUTCDate(endDt.getUTCDate() + 1); // DTEND is exclusive
        endUTC = endDt.toISOString();
    } else {
        startUTC = new Date(`${startDate}T${startTime}:00`).toISOString();
        endUTC   = new Date(`${endDate}T${endTime}:00`).toISOString();
        if (endUTC <= startUTC) {
            alert('End must be after start.');
            return;
        }
    }

    const btn = document.getElementById('ev-save-btn');
    btn.disabled = true;
    btn.textContent = isNew ? 'Creating…' : 'Saving…';

    try {
        const body = {
            summary,
            start:        startUTC,
            end:          endUTC,
            allDay,
            calendarHref: document.getElementById('ev-calendar').value,
            location:     document.getElementById('ev-location').value.trim(),
            description:  document.getElementById('ev-description').value.trim(),
        };
        if (!isNew) body.href = _editingEvent.href;

        const res = await fetch(`api.php?action=${isNew ? 'create' : 'update'}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.__CSRF,
            },
            body: JSON.stringify(body),
        });

        if (!res.ok) {
            const err = await res.json().catch(() => ({}));
            throw new Error(err.error || `HTTP ${res.status}`);
        }

        closeEditModal();
        const evData = await apiFetch('events');
        state.events = evData.events || [];
        render();
    } catch (err) {
        alert('Save failed: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.textContent = isNew ? 'Create' : 'Save';
    }
}

function pad2(n) { return String(n).padStart(2, '0'); }

// ── Boot ──────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', init);
