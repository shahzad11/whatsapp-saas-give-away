// Modal forms, AJAX submits, confirmations and sticky tabs (#24, #25).
//
// Everything here is an *upgrade*. Each behaviour attaches to markup that
// already works on its own: a form with data-ajax posts normally without this
// file, a modal trigger is a link to the same page's form anchor, and a
// destructive button keeps its own server-side confirmation. Nothing below is
// load-bearing for correctness — the server validates, authorises and audits
// regardless of which path a submit took.
//
// The contract with PHP is includes/ajax.php: a JSON body of
//   { ok, message, errors: { field: text }, redirect?, reload? }
// and the X-Requested-With header is what tells the handler to send it.

(function () {
    'use strict';

    // --- Toasts -------------------------------------------------------------

    // A save that closes a modal has nowhere to put its confirmation, so there
    // is one shared, positioned container rather than an alert appended into
    // whatever card happened to be open.
    function toastContainer() {
        var el = document.getElementById('waToasts');
        if (!el) {
            el = document.createElement('div');
            el.id = 'waToasts';
            el.className = 'toast-container position-fixed top-0 end-0 p-3';
            el.style.zIndex = '1090';
            document.body.appendChild(el);
        }
        return el;
    }

    // A server-rendered flash can be any of four severities (#33), so the
    // second argument accepts a variant name as well as the original boolean.
    // The boolean form is kept because every call site inside this file uses
    // it, and "false means danger" is not worth rewriting to be told twice.
    var TOAST_VARIANTS = {
        success: { bg: 'success', light: true, ms: 4000 },
        danger:  { bg: 'danger',  light: true, ms: 8000 },
        warning: { bg: 'warning', light: false, ms: 8000 },
        info:    { bg: 'info',    light: false, ms: 6000 }
    };

    function toast(message, variant) {
        if (!message) return;
        if (typeof variant === 'boolean') variant = variant ? 'success' : 'danger';
        var v = TOAST_VARIANTS[variant] || TOAST_VARIANTS.success;

        var el = document.createElement('div');
        // Warning and info are light backgrounds in Bootstrap's palette, so
        // white text on them fails contrast — the text colour has to follow the
        // variant, not be assumed.
        el.className = 'toast wa-toast align-items-center border-0 show bg-' + v.bg
            + (v.light ? ' text-white' : ' text-dark');
        el.setAttribute('role', variant === 'danger' ? 'alert' : 'status');
        // Errors interrupt; a confirmation waits for a pause in what the screen
        // reader is already saying.
        el.setAttribute('aria-live', variant === 'danger' ? 'assertive' : 'polite');
        el.innerHTML = '<div class="d-flex"><div class="toast-body"></div>'
            + '<button type="button" class="btn-close' + (v.light ? ' btn-close-white' : '')
            + ' me-2 m-auto" aria-label="Dismiss" data-bs-dismiss="toast"></button></div>';
        // textContent, never innerHTML: a message can carry a plan name, an
        // email address or an SMTP server's own error string.
        el.querySelector('.toast-body').textContent = message;
        toastContainer().appendChild(el);

        el.querySelector('.btn-close').addEventListener('click', function () { el.remove(); });
        setTimeout(function () { el.remove(); }, v.ms);
    }

    window.waToast = toast;

    // --- Server-rendered flash messages -------------------------------------

    // includes/flash.php drains the PHP flash queue into a data island rather
    // than into markup, so a redirect-and-flash save lands as the same toast an
    // AJAX save produces. Before this, the two looked nothing alike: one was a
    // floating toast, the other an alert that shoved the page down.
    function showFlashIsland() {
        var island = document.getElementById('waFlash');
        if (!island) return;

        var messages;
        try { messages = JSON.parse(island.dataset.flash || '[]'); } catch (_) { messages = []; }
        // Removed first: a page restored from the back/forward cache runs this
        // again, and a flash message is by definition about a request that has
        // already happened.
        island.remove();
        messages.forEach(function (m) { toast(m.message, m.type); });
    }

    // --- Inline field errors ------------------------------------------------

    function clearErrors(form) {
        form.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
        form.querySelectorAll('[data-ajax-error]').forEach(function (el) { el.remove(); });
    }

    function showErrors(form, errors) {
        Object.keys(errors || {}).forEach(function (field) {
            // Bootstrap's .invalid-feedback only shows next to a sibling marked
            // .is-invalid, which is also how the server-rendered errors look —
            // so a field looks the same however the error arrived.
            var input = form.querySelector('[name="' + field + '"], [name="' + field + '[]"]');
            if (!input) return;
            input.classList.add('is-invalid');

            var note = document.createElement('div');
            note.className = 'invalid-feedback d-block';
            note.setAttribute('data-ajax-error', '1');
            note.textContent = errors[field];
            (input.parentNode || form).appendChild(note);
        });
    }

    // --- Busy state ---------------------------------------------------------

    // Disabling the submit button is not enough on its own: Enter in any field
    // submits the form again, and a double-submitted create makes two rows.
    function setBusy(form, busy) {
        form.dataset.busy = busy ? '1' : '';
        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (btn) {
            btn.disabled = busy;
            if (busy) {
                btn.dataset.label = btn.innerHTML;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>' + (btn.dataset.busyLabel || 'Saving…');
            } else if (btn.dataset.label) {
                btn.innerHTML = btn.dataset.label;
                delete btn.dataset.label;
            }
        });
    }

    // --- AJAX submit --------------------------------------------------------

    function submitAjax(form, submitter) {
        if (form.dataset.busy) return;

        clearErrors(form);
        setBusy(form, true);

        var data = new FormData(form);
        // A submit button's own name/value is part of the submission the browser
        // would have sent (admin/email.php distinguishes Save from Send test
        // exactly this way) and FormData does not include it.
        if (submitter && submitter.name) data.append(submitter.name, submitter.value);

        fetch(form.action || window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: data,
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                setBusy(form, false);

                if (!res.ok) {
                    showErrors(form, res.errors);
                    toast(res.message || 'Please correct the highlighted fields.', false);
                    return;
                }

                var modal = form.closest('.modal');
                if (modal && window.bootstrap) {
                    var instance = bootstrap.Modal.getInstance(modal);
                    if (instance) instance.hide();
                }

                if (res.redirect) { window.location.href = res.redirect; return; }

                // A save can succeed and still need a warning: an appointment
                // cancelled whose customer could not be told (#45). The handler
                // says so with res.variant; without one this is the green toast
                // it always was.
                toast(res.message || 'Saved.', res.variant || true);

                // A listing that has to redraw reloads. Rebuilding a table row
                // in JS would mean the row markup existed twice, in PHP and
                // here, and the two would drift on the first column added.
                // The tab is remembered across the reload, so a tabbed page
                // still comes back where the admin was.
                if (form.dataset.ajaxReload !== 'off') {
                    window.setTimeout(function () { window.location.reload(); }, res.reloadDelay || 600);
                }
            })
            .catch(function () {
                setBusy(form, false);
                // A network failure must not swallow the submit: fall back to a
                // real form post, which is the path that works without JS.
                toast('Could not reach the server — submitting the page instead.', false);
                form.removeAttribute('data-ajax');
                form.submit();
            });
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-ajax')) return;
        e.preventDefault();
        submitAjax(form, e.submitter);
    });

    // --- Confirmation dialogs ----------------------------------------------

    // A Bootstrap modal instead of window.confirm(), for the destructive
    // actions only. The submit still happens through the form, so a browser
    // with JS off gets the form's own onsubmit confirm and, failing that, the
    // server's own guards — deactivating the default plan is refused in PHP,
    // not by this dialog.
    function confirmModal(message, onYes) {
        var el = document.getElementById('waConfirmModal');
        if (!el) {
            el = document.createElement('div');
            el.id = 'waConfirmModal';
            el.className = 'modal fade';
            el.tabIndex = -1;
            el.innerHTML =
                '<div class="modal-dialog modal-dialog-centered modal-sm">'
                + '<div class="modal-content"><div class="modal-body pt-4">'
                + '<p class="mb-0" data-role="message"></p></div>'
                + '<div class="modal-footer border-0 pt-0">'
                + '<button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>'
                + '<button type="button" class="btn btn-sm btn-danger" data-role="yes">Continue</button>'
                + '</div></div></div>';
            document.body.appendChild(el);
        }
        el.querySelector('[data-role="message"]').textContent = message;

        var yes = el.querySelector('[data-role="yes"]');
        var fresh = yes.cloneNode(true);
        yes.parentNode.replaceChild(fresh, yes);

        var modal = window.bootstrap ? bootstrap.Modal.getOrCreateInstance(el) : null;
        fresh.addEventListener('click', function () {
            if (modal) modal.hide();
            onYes();
        });
        if (modal) modal.show();
        else if (window.confirm(message)) onYes();
    }

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-confirm]');
        if (!trigger || trigger.dataset.confirmed) return;

        e.preventDefault();
        confirmModal(trigger.dataset.confirm, function () {
            // Marked before re-dispatching so the same click does not reopen the
            // dialog. Cleared afterwards: the row may still be on the page.
            trigger.dataset.confirmed = '1';
            trigger.click();
            delete trigger.dataset.confirmed;
        });
    });

    // --- Promoting a server-rendered card into a modal ----------------------

    // A page renders its form once, as a plain card marked
    // data-modal-shell="<id>". Here it is moved into a real Bootstrap modal.
    //
    // The alternative was rendering the form twice — once in a modal, once in a
    // <noscript> fallback — which puts every field id in the document twice and
    // silently breaks the <label for> pairs of whichever copy comes second. One
    // render, moved, keeps a single source of truth and leaves the no-JS case as
    // the ordinary full-page form it always was.
    function promoteShells() {
        if (!window.bootstrap) return;

        document.querySelectorAll('[data-modal-shell]').forEach(function (shell) {
            var id = shell.dataset.modalShell;
            var modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.id = id;
            modal.tabIndex = -1;
            modal.innerHTML =
                '<div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">'
                + '<div class="modal-header"><h5 class="modal-title" data-role="modal-title"></h5>'
                + '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
                + '<div class="modal-body" data-role="modal-body"></div>'
                + '</div></div>';
            document.body.appendChild(modal);

            modal.querySelector('[data-role="modal-title"]').textContent = shell.dataset.modalTitle || '';

            // The card chrome goes: a card header inside a modal header is two
            // titles. Only the body's contents are moved.
            var body = shell.querySelector('.card-body');
            modal.querySelector('[data-role="modal-body"]').appendChild(body || shell);
            // Only when the body was a child — otherwise the shell *is* the body
            // that was just moved, and removing it would throw the form away.
            if (body) shell.remove();

            // A page that arrived already editing something (?edit=N) opens
            // straight away, so the link the admin clicked without JavaScript
            // still lands them in the editor with JavaScript.
            if (shell.dataset.modalOpen === '1') bootstrap.Modal.getOrCreateInstance(modal).show();
        });
    }

    // Bootstrap tooltips are opt-in: a data-bs-toggle="tooltip" attribute does
    // nothing until an instance is created, so the help icons have to be wired
    // up here. Focus is included alongside hover so keyboard users get the tip.
    function initTooltips() {
        if (!window.bootstrap) return;
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
            bootstrap.Tooltip.getOrCreateInstance(el, { trigger: 'hover focus' });
        });
    }

    // Live character counters (#36 follow-on). A field with
    // data-char-count="<id>" has its counter element kept in step while typing.
    // The count is server-rendered correct on load, so this only has to update —
    // it is enhancement, and bails out silently if either half is missing.
    function initCharCounts() {
        document.querySelectorAll('[data-char-count]').forEach(function (el) {
            var counter = document.getElementById(el.dataset.charCount);
            var max = el.getAttribute('maxlength');
            if (!counter || !max) return;
            el.addEventListener('input', function () {
                counter.textContent = el.value.length + '/' + max;
            });
        });
    }

    function onReady() {
        promoteShells();
        initTooltips();
        initCharCounts();
        showFlashIsland();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onReady);
    } else {
        onReady();
    }

    // --- Modal form triggers -----------------------------------------------

    // A trigger with data-modal-url replaces the modal's body with a fragment
    // fetched from the server. Used where a form is too big to reconstruct from
    // data-* attributes — the plan editor's five limits, seven switches and model
    // grant matrix — so the populated form comes from the one place that renders
    // it. The href still points at the full page for the no-JS case.
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-modal-url]');
        if (!trigger || !window.bootstrap) return;

        var modal = document.querySelector(trigger.dataset.modalTarget || '');
        var body = modal && modal.querySelector('[data-role="modal-body"]');
        if (!body) return;                        // nothing to fill: let the link work
        e.preventDefault();

        var title = modal.querySelector('[data-role="modal-title"]');
        if (title) title.textContent = trigger.dataset.modalTitle || '';

        body.innerHTML = '<div class="text-center text-muted py-4">'
            + '<span class="spinner-border spinner-border-sm me-2"></span>Loading…</div>';
        bootstrap.Modal.getOrCreateInstance(modal).show();

        fetch(trigger.dataset.modalUrl, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(function (html) { body.innerHTML = html; })
            .catch(function () {
                // Never leave a spinner spinning. The href is the page that
                // definitely works, so offer it.
                body.innerHTML = '<div class="alert alert-danger mb-0">Could not load the form. '
                    + '<a href="' + trigger.getAttribute('href') + '">Open it as a page instead</a>.</div>';
            });
    });

    // An edit trigger carries the row's values as data-field-* attributes and
    // the modal is filled from them, so opening one costs no request. Its href
    // still points at the server-rendered form for the no-JS case.
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-modal-target]');
        // data-modal-url triggers are handled above, and both listeners seeing the
        // same click would show the modal twice and clear a form that has not
        // been fetched yet.
        if (!trigger || trigger.dataset.modalUrl || !window.bootstrap) return;

        var modal = document.querySelector(trigger.dataset.modalTarget);
        if (!modal) return;                       // no modal on the page: let the link work
        e.preventDefault();

        var form = modal.querySelector('form');
        if (form) {
            clearErrors(form);
            if (trigger.dataset.modalReset === 'on') form.reset();

            Object.keys(trigger.dataset).forEach(function (key) {
                if (key.indexOf('field') !== 0 || key === 'field') return;
                // data-field-service-name -> serviceName -> service_name
                var name = key.slice(5).replace(/^./, function (c) { return c.toLowerCase(); })
                    .replace(/[A-Z]/g, function (c) { return '_' + c.toLowerCase(); });
                var input = form.querySelector('[name="' + name + '"]');
                if (!input) return;
                if (input.type === 'checkbox') input.checked = trigger.dataset[key] === '1';
                else input.value = trigger.dataset[key];
            });
        }

        var title = modal.querySelector('[data-role="modal-title"]');
        if (title && trigger.dataset.modalTitle) title.textContent = trigger.dataset.modalTitle;

        bootstrap.Modal.getOrCreateInstance(modal).show();
    });

    // Enter submits the primary action; Escape closes — Bootstrap already does
    // Escape, so only the first needs handling, and only for inputs, because in
    // a textarea Enter means a newline.
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || e.target.tagName === 'TEXTAREA') return;
        var modal = e.target.closest('.modal');
        if (!modal) return;
        var form = e.target.closest('form');
        if (!form) return;
        e.preventDefault();
        var submit = form.querySelector('button[type="submit"], input[type="submit"]');
        if (submit) submit.click();
    });

    // --- Sticky tabs --------------------------------------------------------

    // A tabbed settings page that reloads after a save used to come back on the
    // first tab, with the admin's change apparently gone. The active tab is
    // remembered per page so a reload — AJAX-triggered or a plain redirect —
    // lands where they were. A #hash wins, because that is an explicit request.
    (function stickyTabs() {
        var tabs = document.querySelectorAll('[data-bs-toggle="tab"]');
        if (!tabs.length || !window.bootstrap) return;

        var key = 'waTab:' + window.location.pathname;

        var wanted = window.location.hash ? window.location.hash : window.sessionStorage.getItem(key);
        if (wanted) {
            var restore = document.querySelector('[data-bs-toggle="tab"][data-bs-target="' + wanted + '"]');
            if (restore) bootstrap.Tab.getOrCreateInstance(restore).show();
        }

        tabs.forEach(function (tab) {
            tab.addEventListener('shown.bs.tab', function () {
                window.sessionStorage.setItem(key, tab.dataset.bsTarget || '');
            });
        });
    })();
})();
