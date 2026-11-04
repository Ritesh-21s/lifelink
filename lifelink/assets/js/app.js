/**
 * LifeLink – Main Application JavaScript
 * Handles: navbar, flash, ticker, count-up, confirm dialog, forms, tabs
 */

'use strict';

/* ── Navbar mobile toggle ───────────────────────────────────── */
(function() {
  const toggle  = document.getElementById('navToggle');
  const links   = document.getElementById('navLinks');
  if (!toggle || !links) return;

  toggle.addEventListener('click', () => {
    const open = links.classList.toggle('open');
    toggle.classList.toggle('open', open);
    toggle.setAttribute('aria-expanded', String(open));
  });

  // Close on outside click
  document.addEventListener('click', e => {
    if (!toggle.contains(e.target) && !links.contains(e.target)) {
      links.classList.remove('open');
      toggle.classList.remove('open');
      toggle.setAttribute('aria-expanded', 'false');
    }
  });
})();

/* ── User dropdown ──────────────────────────────────────────── */
(function() {
  const btn      = document.getElementById('userMenuBtn');
  const dropdown = document.getElementById('userDropdown');
  if (!btn || !dropdown) return;

  btn.addEventListener('click', e => {
    e.stopPropagation();
    const open = dropdown.classList.toggle('open');
    btn.setAttribute('aria-expanded', String(open));
  });

  document.addEventListener('click', () => {
    dropdown.classList.remove('open');
    btn.setAttribute('aria-expanded', 'false');
  });
})();

/* ── Flash message dismiss ──────────────────────────────────── */
document.querySelectorAll('.flash-close').forEach(btn => {
  btn.addEventListener('click', () => {
    btn.closest('.flash').remove();
  });
});

// Auto-dismiss flash after 5s
setTimeout(() => {
  document.querySelectorAll('.flash').forEach(el => {
    el.style.transition = 'opacity 0.5s';
    el.style.opacity = '0';
    setTimeout(() => el.remove(), 500);
  });
}, 5000);

/* ── Animated count-up ──────────────────────────────────────── */
function animateCount(el, target, duration = 1500) {
  const start = performance.now();
  const startVal = 0;
  function step(now) {
    const p = Math.min((now - start) / duration, 1);
    const eased = 1 - Math.pow(1 - p, 3); // ease-out cubic
    el.textContent = Math.floor(startVal + eased * (target - startVal)).toLocaleString();
    if (p < 1) requestAnimationFrame(step);
    else el.textContent = target.toLocaleString();
  }
  requestAnimationFrame(step);
}

function initCounters() {
  const els = document.querySelectorAll('[data-count]');
  if (!els.length) return;
  const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        const el  = entry.target;
        const val = parseInt(el.dataset.count, 10);
        animateCount(el, val);
        observer.unobserve(el);
      }
    });
  }, { threshold: 0.3 });
  els.forEach(el => observer.observe(el));
}
initCounters();

/* ── Footer lives helped ────────────────────────────────────── */
(function() {
  const el = document.getElementById('footerLivesCount');
  if (!el) return;
  fetch(APP_URL + '/api/stats.php?metric=lives_helped')
    .then(r => r.json())
    .then(d => {
      if (d.value !== undefined) animateCount(el, d.value);
    })
    .catch(() => { el.textContent = '—'; });
})();

/* ── Live Ticker ────────────────────────────────────────────── */
let tickerLastUpdated = 0;

function buildTickerItem(req) {
  const isCI = req.urgency === 'Critical';
  return `<span class="ticker-item">
    ${isCI ? '<span class="pulse-dot"></span>' : ''}
    <span class="blood-badge badge-${bgClass(req.blood_group)}">${req.blood_group}</span>
    <strong>${escHtml(req.hospital)}</strong> · ${escHtml(req.area_name)}
    · ${req.units_still_needed} unit(s) needed
    · <em>${escHtml(req.urgency)}</em>
    · ${escHtml(req.posted_ago)}
  </span>`;
}

function bgClass(group) {
  const m = {'O-':'o-neg','O+':'o-pos','A+':'a-pos','A-':'a-neg','B+':'b-pos','B-':'b-neg','AB+':'ab-pos','AB-':'ab-neg'};
  return m[group] || 'default';
}

function escHtml(str) {
  const d = document.createElement('div');
  d.appendChild(document.createTextNode(str || ''));
  return d.innerHTML;
}

function fetchTicker() {
  const container = document.getElementById('tickerItems');
  if (!container) return;
  fetch(APP_URL + '/api/urgent_requests.php?last_updated=' + tickerLastUpdated)
    .then(r => r.json())
    .then(data => {
      if (data.no_change) return; // no new data
      tickerLastUpdated = data.last_updated || 0;
      if (!data.requests || data.requests.length === 0) {
        container.innerHTML = '<span class="ticker-item">No urgent requests at this time. ❤️</span>';
        return;
      }
      // Duplicate items for seamless scroll
      const items = data.requests.map(buildTickerItem).join('');
      container.innerHTML = items + items; // doubled for loop
    })
    .catch(() => {}); // silent fail
}

(function initTicker() {
  if (!document.getElementById('tickerItems')) return;
  fetchTicker();
  setInterval(fetchTicker, (TICKER_POLL_SECONDS || 12) * 1000);
})();

/* ── Confirm Dialog ─────────────────────────────────────────── */
const confirmOverlay = document.getElementById('confirmOverlay');
const confirmOkBtn   = document.getElementById('confirmOk');
const confirmCancelBtn = document.getElementById('confirmCancel');
let confirmResolve = null;

function showConfirm(message = 'This action cannot be undone.', title = 'Are you sure?') {
  return new Promise(resolve => {
    if (!confirmOverlay) { resolve(window.confirm(message)); return; }
    document.getElementById('confirmTitle').textContent   = title;
    document.getElementById('confirmMessage').textContent = message;
    confirmOverlay.classList.add('open');
    confirmOverlay.setAttribute('aria-hidden', 'false');
    confirmOkBtn.focus();
    confirmResolve = resolve;
  });
}

confirmOkBtn?.addEventListener('click', () => {
  confirmOverlay.classList.remove('open');
  confirmOverlay.setAttribute('aria-hidden', 'true');
  confirmResolve && confirmResolve(true);
});
confirmCancelBtn?.addEventListener('click', () => {
  confirmOverlay.classList.remove('open');
  confirmOverlay.setAttribute('aria-hidden', 'true');
  confirmResolve && confirmResolve(false);
});
confirmOverlay?.addEventListener('click', e => {
  if (e.target === confirmOverlay) {
    confirmOverlay.classList.remove('open');
    confirmResolve && confirmResolve(false);
  }
});

// Attach confirm to data-confirm buttons/links
document.querySelectorAll('[data-confirm]').forEach(el => {
  el.addEventListener('click', async e => {
    e.preventDefault();
    const msg  = el.dataset.confirm || 'Are you sure?';
    const confirmed = await showConfirm(msg);
    if (confirmed) {
      if (el.tagName === 'A') window.location.href = el.href;
      else if (el.form) el.form.submit();
    }
  });
});

/* ── CSRF fetch helper ───────────────────────────────────────── */
async function postJSON(url, data) {
  const body = new URLSearchParams({ ...data, csrf_token: CSRF_TOKEN });
  const resp = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': CSRF_TOKEN },
    body: body.toString()
  });
  return resp.json();
}

/* ── Availability toggle ────────────────────────────────────── */
(function() {
  const toggle = document.getElementById('availabilityToggle');
  if (!toggle) return;
  toggle.addEventListener('change', async function() {
    const track = document.querySelector('.toggle-track');
    const label = document.getElementById('availabilityLabel');
    const val   = this.checked ? 1 : 0;
    try {
      const data = await postJSON(APP_URL + '/api/toggle_availability.php', { available: val });
      if (data.success) {
        track && track.classList.toggle('on', this.checked);
        if (label) label.textContent = this.checked ? 'Available' : 'Not Available';
      } else {
        this.checked = !this.checked; // revert
        alert(data.message || 'Could not update availability.');
      }
    } catch {
      this.checked = !this.checked;
    }
  });
})();

/* ── Respond to request (accept/decline) ───────────────────── */
document.querySelectorAll('[data-respond]').forEach(btn => {
  btn.addEventListener('click', async function() {
    const action = this.dataset.respond;
    const requestId = this.dataset.requestId;
    if (!requestId) return;

    if (action === 'accept') {
      const ok = await showConfirm('Accept this request? The requester will be able to contact you.', 'Accept Request');
      if (!ok) return;
    }

    this.disabled = true;
    this.innerHTML = '<span class="spinner"></span>';

    try {
      const data = await postJSON(APP_URL + '/api/respond.php', { request_id: requestId, action });
      if (data.success) {
        showFlash('success', data.message);
        setTimeout(() => location.reload(), 1200);
      } else {
        showFlash('error', data.message || 'Error processing response.');
        this.disabled = false;
        this.textContent = action === 'accept' ? 'Accept' : 'Decline';
      }
    } catch {
      showFlash('error', 'Network error. Please try again.');
      this.disabled = false;
    }
  });
});

/* ── Camp interest toggle ───────────────────────────────────── */
document.querySelectorAll('[data-camp-interest]').forEach(btn => {
  btn.addEventListener('click', async function() {
    const campId = this.dataset.campInterest;
    this.disabled = true;
    try {
      const data = await postJSON(APP_URL + '/api/camp_interest.php', { camp_id: campId });
      if (data.success) {
        this.textContent = data.attending ? "✓ Attending" : "I'll Attend";
        this.classList.toggle('btn-success', data.attending);
        this.classList.toggle('btn-outline', !data.attending);
        const counter = document.querySelector(`[data-camp-count="${campId}"]`);
        if (counter) counter.textContent = data.count;
      }
    } finally {
      this.disabled = false;
    }
  });
});

/* ── Inline flash (JS-only) ─────────────────────────────────── */
function showFlash(type, message) {
  const container = document.querySelector('.flash-container .container') || document.querySelector('.container');
  if (!container) { alert(message); return; }
  const el = document.createElement('div');
  el.className = `flash flash-${type} slide-in`;
  el.innerHTML = `<span>${type === 'success' ? '✅' : '❌'}</span>${escHtml(message)}<button class="flash-close" onclick="this.parentElement.remove()">&times;</button>`;
  container.prepend(el);
  setTimeout(() => { el.style.opacity='0'; setTimeout(()=>el.remove(),500); }, 4000);
}

/* ── Tab panels ─────────────────────────────────────────────── */
document.querySelectorAll('[data-tab]').forEach(tab => {
  tab.addEventListener('click', function() {
    const group = this.dataset.tabGroup;
    document.querySelectorAll(`[data-tab][data-tab-group="${group}"]`).forEach(t => t.classList.remove('active'));
    document.querySelectorAll(`[data-panel][data-tab-group="${group}"]`).forEach(p => p.classList.add('hidden'));
    this.classList.add('active');
    const panel = document.getElementById(this.dataset.tab);
    panel && panel.classList.remove('hidden');
  });
});

/* ── Form validation helpers ────────────────────────────────── */
(function() {
  document.querySelectorAll('form[data-validate]').forEach(form => {
    form.addEventListener('submit', function(e) {
      let valid = true;
      this.querySelectorAll('[required]').forEach(field => {
        const parent = field.closest('.form-group');
        const err    = parent?.querySelector('.field-error');
        if (!field.value.trim()) {
          valid = false;
          field.setAttribute('aria-invalid', 'true');
          if (err) err.textContent = 'This field is required.';
          parent?.classList.add('input-error');
        } else {
          field.removeAttribute('aria-invalid');
          if (err) err.textContent = '';
          parent?.classList.remove('input-error');
        }
      });
      if (!valid) e.preventDefault();
    });
  });
})();

/* ── Date range helpers ─────────────────────────────────────── */
(function() {
  const todayInput = document.getElementById('needed_by');
  if (todayInput) {
    const now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    todayInput.min = now.toISOString().slice(0, 16);
  }
})();

/* ── Smooth page transitions ────────────────────────────────── */
document.querySelectorAll('a[href]').forEach(link => {
  if (link.hostname === window.location.hostname && !link.hasAttribute('data-confirm')) {
    // No heavy animation, just ensure no jank
  }
});
