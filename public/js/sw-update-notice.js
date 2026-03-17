// App-Version (automatisch aktualisiert von cli/release.php)
var APP_VERSION = '1.3.1';
(function(){var el=document.querySelector('.app-version');if(el)el.textContent='v'+APP_VERSION;})();

(function () {
  'use strict';

  if (!('serviceWorker' in navigator)) return;

  var noticeVisible = false;

  function showUpdateNotice() {
    if (noticeVisible || document.getElementById('sw-update-notice')) return;
    noticeVisible = true;

    var el = document.createElement('div');
    el.id = 'sw-update-notice';
    el.className = 'sw-update-notice';
    el.innerHTML = '' +
      '<span class="sw-update-text">Neue Version verfügbar</span>' +
      '<div class="sw-update-actions">' +
      '  <button type="button" class="sw-update-later" id="sw-update-later">Später</button>' +
      '  <button type="button" class="sw-update-refresh" id="sw-update-refresh">Aktualisieren</button>' +
      '</div>';

    document.body.appendChild(el);

    var laterBtn = document.getElementById('sw-update-later');
    var refreshBtn = document.getElementById('sw-update-refresh');

    if (laterBtn) {
      laterBtn.addEventListener('click', function () {
        el.remove();
        noticeVisible = false;
      });
    }

    if (refreshBtn) {
      refreshBtn.addEventListener('click', function () {
        location.reload();
      });
    }
  }

  function watchRegistration(registration) {
    if (!registration) return;

    if (registration.waiting) {
      showUpdateNotice();
    }

    registration.addEventListener('updatefound', function () {
      var installing = registration.installing;
      if (!installing) return;

      installing.addEventListener('statechange', function () {
        if (installing.state === 'installed' && navigator.serviceWorker.controller) {
          showUpdateNotice();
        }
      });
    });
  }

  navigator.serviceWorker.register('/service-worker.js')
    .then(function (registration) {
      watchRegistration(registration);
    })
    .catch(function () {
      // Registration failed: keep page functional without notification.
    });

  navigator.serviceWorker.addEventListener('controllerchange', function () {
    showUpdateNotice();
  });
})();
