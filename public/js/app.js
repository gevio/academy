// public/js/app.js

document.addEventListener('DOMContentLoaded', () => {
    // ── Star Rating Widget ──
    document.querySelectorAll('.stars').forEach(container => {
        const name = container.dataset.name;
        const input = container.parentElement.querySelector(`input[name="${name}"]`);

        for (let i = 1; i <= 5; i++) {
            const star = document.createElement('span');
            star.className = 'star';
            star.textContent = '★';
            star.dataset.value = i;

            star.addEventListener('click', () => {
                input.value = i;
                container.querySelectorAll('.star').forEach(s => {
                    s.classList.toggle('active', parseInt(s.dataset.value) <= i);
                });
            });

            star.addEventListener('mouseenter', () => {
                container.querySelectorAll('.star').forEach(s => {
                    s.style.color = parseInt(s.dataset.value) <= i ? '#e76f51' : '#ddd';
                });
            });

            star.addEventListener('mouseleave', () => {
                const current = parseInt(input.value) || 0;
                container.querySelectorAll('.star').forEach(s => {
                    s.style.color = '';
                    s.classList.toggle('active', parseInt(s.dataset.value) <= current);
                });
            });

            container.appendChild(star);
        }
    });

    // ── Form Validation ──
    const form = document.getElementById('feedback-form');
    if (form) {
        form.addEventListener('submit', (e) => {
            const ratings = form.querySelectorAll('.stars');
            let allRated = true;
            ratings.forEach(r => {
                const name = r.dataset.name;
                const val = form.querySelector(`input[name="${name}"]`).value;
                if (!val || val === '0') {
                    allRated = false;
                    r.parentElement.style.border = '2px solid #e63946';
                } else {
                    r.parentElement.style.border = 'none';
                }
            });
            if (!allRated) {
                e.preventDefault();
                alert('Bitte bewerte alle Kategorien mit mindestens einem Stern.');
            }
        });
    }

// ── Optimistic Upvote ──────────────────────────────
const UPVOTE_MAX = 10;
const storageKey = 'as26_upvotes';

function getUpvoted() {
    try { return JSON.parse(localStorage.getItem(storageKey) || '[]'); }
    catch { return []; }
}
function saveUpvoted(arr) {
    localStorage.setItem(storageKey, JSON.stringify(arr));
}

document.querySelectorAll('.upvote-btn').forEach(btn => {
    const qId = btn.dataset.questionId;
    if (!qId) return;

    // Bereits gevoted? → Button sofort deaktivieren
    if (getUpvoted().includes(qId)) {
        btn.disabled = true;
        btn.classList.add('voted');
    }

    btn.addEventListener('click', async () => {
        const countEl = btn.querySelector('.vote-count');
        const current = parseInt(countEl.textContent) || 0;

        // Schon gevoted?
        if (getUpvoted().includes(qId)) {
            btn.classList.add('shake');
            setTimeout(() => btn.classList.remove('shake'), 600);
            return;
        }

        // Max-Limit erreicht?
        if (current >= UPVOTE_MAX) {
            alert('Das Upvote-Limit wurde erreicht.');
            return;
        }

        // ── Optimistic Update ──
        countEl.textContent = current + 1;
        btn.disabled = true;
        btn.classList.add('voted');
        const upvoted = getUpvoted();
        upvoted.push(qId);
        saveUpvoted(upvoted);

        // ── Async POST (kein Page-Reload!) ──
        try {
            const wId = btn.dataset.workshopId;
            const resp = await fetch('/w/' + encodeURIComponent(wId) + '/qa', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'upvote_id=' + encodeURIComponent(qId)
                    + '&workshop_id=' + encodeURIComponent(wId)
                    + '&ajax=1',
            });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
        } catch (err) {
            // ── Rollback bei Fehler ──
            countEl.textContent = current;
            btn.disabled = false;
            btn.classList.remove('voted');
            const arr = getUpvoted();
            const idx = arr.indexOf(qId);
            if (idx > -1) arr.splice(idx, 1);
            saveUpvoted(arr);
            console.error('Upvote failed:', err);
        }
    });
});

});
