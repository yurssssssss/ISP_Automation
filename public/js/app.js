// A page restored from the browser's back/forward cache never reached the
// server, so it could show the login form while signed in, or a dashboard
// after signing out. Reload it so the server decides (see PreventBackHistory).
window.addEventListener('pageshow', function (event) {
    if (event.persisted) {
        window.location.reload();
    }
});

// Live email check for inputs marked data-email-check="existing|new". Runs
// when typing pauses or the field is left, and shows one line under it: an
// incomplete address, a provider typo ("Did you mean …@gmail.com?"), an
// unknown provider, or — for an admin adding a customer — whether the email
// is already used. Advice only; the submit is still validated on the server.
(function () {
    var POPULAR = ['gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'icloud.com'];
    var KNOWN = POPULAR.concat(['yahoo.com.ph', 'ymail.com', 'live.com', 'me.com', 'mail.com', 'aol.com', 'proton.me', 'protonmail.com']);
    var FORMAT = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
    var PAUSE_MS = 700;
    var results = {};
    var csrf = document.querySelector('meta[name="csrf-token"]');

    function distance(a, b) {
        var row = [];
        for (var j = 0; j <= b.length; j++) {
            row[j] = j;
        }
        for (var i = 1; i <= a.length; i++) {
            var previous = row[0];
            row[0] = i;
            for (j = 1; j <= b.length; j++) {
                var current = row[j];
                row[j] = Math.min(row[j] + 1, row[j - 1] + 1, previous + (a[i - 1] === b[j - 1] ? 0 : 1));
                previous = current;
            }
        }
        return row[b.length];
    }

    // "juan@gmial.com" → "juan@gmail.com"; null when the provider looks fine.
    function suggestion(email) {
        var at = email.lastIndexOf('@');
        var domain = email.slice(at + 1).toLowerCase();
        if (KNOWN.indexOf(domain) !== -1) {
            return null;
        }
        var best = null;
        var bestDistance = 3;
        POPULAR.forEach(function (candidate) {
            var d = distance(domain, candidate);
            if (d < bestDistance) {
                bestDistance = d;
                best = candidate;
            }
        });
        return best ? email.slice(0, at + 1) + best : null;
    }

    function line(input) {
        if (!input._emailLine) {
            input._emailLine = document.createElement('div');
            input._emailLine.setAttribute('aria-live', 'polite');
            input.insertAdjacentElement('afterend', input._emailLine);
        }
        return input._emailLine;
    }

    // state: '' | 'busy' | 'ok' | 'warn' | 'bad'
    function show(input, state, text, fix) {
        var el = line(input);
        input.classList.toggle('is-valid', state === 'ok');
        input.classList.toggle('is-invalid', state === 'bad');
        el.className = {
            '': 'd-none',
            busy: 'form-text',
            ok: 'valid-feedback d-block',
            warn: 'form-text text-warning-emphasis',
            bad: 'invalid-feedback d-block',
        }[state];
        el.textContent = state === 'busy' ? 'Checking email…' : (text || '');

        if (fix) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-link btn-sm p-0 ms-1 align-baseline';
            button.textContent = 'Use it';
            button.addEventListener('click', function () {
                input.value = fix;
                input.focus();
                check(input, true);
            });
            el.appendChild(button);
        }
    }

    function render(input, result) {
        // On login / forgot password a good address shows nothing: a green
        // tick there would read as "this account exists".
        if (result.ok && !result.message) {
            return show(input, '');
        }
        show(input, result.ok ? 'ok' : 'bad', result.message);
    }

    function check(input, final) {
        var email = input.value.trim();
        var context = input.dataset.emailCheck;

        if (!email) {
            return show(input, '');
        }
        if (!FORMAT.test(email)) {
            // Still typing: don't complain about an unfinished address.
            return final ? show(input, 'bad', 'Enter a complete email address, like name@gmail.com.') : show(input, '');
        }

        var fix = suggestion(email);
        if (fix) {
            return show(input, 'warn', 'Did you mean ' + fix + '?', fix);
        }

        var key = context + '|' + email.toLowerCase();
        if (results[key]) {
            return render(input, results[key]);
        }

        show(input, 'busy');
        var body = new FormData();
        body.append('email', email);
        body.append('context', context);

        fetch(input.dataset.emailCheckUrl, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf ? csrf.content : '' },
            body: body,
            credentials: 'same-origin',
        })
            .then(function (response) {
                if (!response.ok) {
                    throw response;
                }
                return response.json();
            })
            .then(function (result) {
                results[key] = result;
                if (input.value.trim() === email) {
                    render(input, result);
                }
            })
            .catch(function () {
                // Rate limited or offline: say nothing; the submit still validates.
                if (input.value.trim() === email) {
                    show(input, '');
                }
            });
    }

    document.querySelectorAll('input[data-email-check]').forEach(function (input) {
        var timer;
        var serverErrorCleared = false;

        input.addEventListener('input', function () {
            // The server's message was about the old value.
            if (!serverErrorCleared) {
                serverErrorCleared = true;
                input.parentNode.querySelectorAll('.invalid-feedback').forEach(function (el) {
                    if (el !== input._emailLine) {
                        el.remove();
                    }
                });
            }
            clearTimeout(timer);
            show(input, '');
            timer = setTimeout(function () { check(input, false); }, PAUSE_MS);
        });

        input.addEventListener('blur', function () {
            clearTimeout(timer);
            check(input, true);
        });

        // A value kept after a failed submit is checked right away, unless
        // the server already explained what's wrong with it.
        if (input.value.trim() && !input.classList.contains('is-invalid')) {
            check(input, true);
        }
    });
})();

// Live checklist under a new-password field. Mirrors Password::defaults() in
// AppServiceProvider; the server still validates everything.
document.querySelectorAll('[data-password-rules]').forEach(function (list) {
    var input = document.getElementById(list.dataset.passwordRules);
    if (!input) {
        return;
    }

    var tests = {
        length: function (value) { return value.length >= 8; },
        upper: function (value) { return /\p{Lu}/u.test(value); },
        lower: function (value) { return /\p{Ll}/u.test(value); },
        number: function (value) { return /\p{N}/u.test(value); },
        symbol: function (value) { return /[\p{Z}\p{S}\p{P}]/u.test(value); },
    };

    function update() {
        list.querySelectorAll('[data-rule]').forEach(function (item) {
            var passed = tests[item.dataset.rule](input.value);
            item.classList.toggle('text-success', passed);
            item.textContent = (passed ? '✓ ' : '') + item.textContent.replace(/^✓ /, '');
        });
    }

    input.addEventListener('input', update);
    update();
});
