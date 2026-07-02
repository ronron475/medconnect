(function () {
  function initProfilePictureUpload(root) {
    if (!root || root.dataset.profileUploadReady === '1') {
      return;
    }
    root.dataset.profileUploadReady = '1';

    const input = root.querySelector('input[type="file"]');
    const preview = root.querySelector('[data-profile-preview]');
    const status = root.querySelector('[data-profile-status]');
    const trigger = root.querySelector('[data-profile-trigger]');
    const uploadUrl = root.dataset.uploadUrl || '';
    const csrf = root.dataset.csrf || '';

    if (!input || !uploadUrl) {
      return;
    }

    const setStatus = (message, isError) => {
      if (!status) {
        return;
      }
      status.textContent = message || '';
      status.classList.toggle('is-error', !!isError);
      status.classList.toggle('is-success', !!message && !isError);
    };

    const openPicker = () => input.click();

    if (trigger) {
      trigger.addEventListener('click', openPicker);
    }

    input.addEventListener('change', async () => {
      const file = input.files && input.files[0];
      if (!file) {
        return;
      }

      if (file.size > 2 * 1024 * 1024) {
        setStatus('Profile picture must be 2 MB or smaller.', true);
        input.value = '';
        return;
      }

      const fd = new FormData();
      fd.append('profile_picture', file);
      fd.append('csrf_token', csrf);

      if (trigger) {
        trigger.disabled = true;
      }
      setStatus('Uploading…', false);

      try {
        const res = await fetch(uploadUrl, {
          method: 'POST',
          body: fd,
          credentials: 'include',
          cache: 'no-store',
        });
        const data = await res.json();

        if (!data.success) {
          setStatus(data.message || 'Upload failed.', true);
          return;
        }

        const bustUrl = data.url
          ? data.url + (data.url.includes('?') ? '&' : '?') + '_=' + Date.now()
          : '';

        const applyImageToWrap = (wrap) => {
          if (!wrap || !bustUrl) return;
          let img = wrap.querySelector('[data-profile-avatar-img], .profile-avatar__img');
          const avatar = wrap.querySelector('.profile-avatar') || wrap;
          const initials = wrap.querySelector('.profile-avatar__initials');

          if (!img && avatar) {
            avatar.innerHTML =
              '<img src="' +
              bustUrl +
              '" alt="Profile picture" class="profile-avatar__img" data-profile-avatar-img loading="lazy">';
            img = avatar.querySelector('img');
          }

          if (img) {
            img.src = bustUrl;
            img.style.display = '';
          }
          if (initials) {
            initials.style.display = 'none';
          }
        };

        if (preview) {
          if (preview.tagName === 'IMG') {
            preview.src = bustUrl;
          } else {
            applyImageToWrap(preview);
            if (!preview.querySelector('img') && bustUrl) {
              preview.innerHTML =
                '<div class="profile-avatar profile-avatar--xl"><img src="' +
                bustUrl +
                '" alt="Profile picture" class="profile-avatar__img" data-profile-avatar-img loading="lazy"></div>';
            }
          }
        }

        document.querySelectorAll('[data-profile-avatar-wrap]').forEach(applyImageToWrap);
        document.querySelectorAll('[data-profile-avatar-img], .profile-avatar__img').forEach((img) => {
          if (bustUrl) {
            img.src = bustUrl;
            img.style.display = '';
          }
        });

        setStatus(data.message || 'Profile picture updated.', false);
        setTimeout(() => window.location.reload(), 900);
      } catch {
        setStatus('Network error while uploading.', true);
      } finally {
        if (trigger) {
          trigger.disabled = false;
        }
        input.value = '';
      }
    });
  }

  document.querySelectorAll('[data-profile-upload]').forEach(initProfilePictureUpload);
})();
