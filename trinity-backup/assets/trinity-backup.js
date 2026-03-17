(() => {
  // === THEME SWITCHER ===
  const themeSwitcher = document.querySelector('.trinity-theme-switcher');
  const trinityBackup = document.querySelector('.trinity-backup');
  const prefersDark = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  let prefersListenerBound = false;
  
  // Get current effective theme (resolves 'auto' to actual light/dark)
  const getCurrentTheme = () => {
    const savedTheme = trinityBackup?.getAttribute('data-theme') || 'auto';
    if (savedTheme === 'auto') {
      return prefersDark && prefersDark.matches ? 'dark' : 'light';
    }
    return savedTheme;
  };

  const setBodyThemeClass = (selectedTheme) => {
    const effective = selectedTheme === 'auto'
      ? (prefersDark && prefersDark.matches ? 'dark' : 'light')
      : selectedTheme;

    document.body.classList.remove('trinity-theme-light', 'trinity-theme-dark');
    document.body.classList.add(effective === 'dark' ? 'trinity-theme-dark' : 'trinity-theme-light');
  };

  const bindPrefersListenerIfNeeded = () => {
    if (!prefersDark || prefersListenerBound || !prefersDark.addEventListener) {
      return;
    }
    prefersDark.addEventListener('change', () => {
      const selectedTheme = trinityBackup?.getAttribute('data-theme') || 'auto';
      if (selectedTheme === 'auto') {
        setBodyThemeClass('auto');
      }
    });
    prefersListenerBound = true;
  };
  
  // Save theme to user settings via AJAX
  const saveTheme = async (theme) => {
    const params = new URLSearchParams();
    params.append('action', 'trinity_backup_save_theme');
    params.append('nonce', TrinityBackup.nonce);
    params.append('theme', theme);
    
    try {
      await fetch(TrinityBackup.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: params
      });
    } catch (e) {
      // Silent fail - theme will still work visually
    }
  };
  
  // Theme is already set by PHP from user meta, so no initTheme needed on load
  // Just handle click events
  if (themeSwitcher) {
    if (trinityBackup) {
      setBodyThemeClass(trinityBackup.getAttribute('data-theme') || 'auto');
      bindPrefersListenerIfNeeded();
    }

    themeSwitcher.addEventListener('click', (e) => {
      const btn = e.target.closest('.trinity-theme-btn');
      if (!btn) return;
      
      const theme = btn.dataset.theme;
      
      // Update all instances to ensure everything is covered
      const allBackups = document.querySelectorAll('.trinity-backup');
      allBackups.forEach(el => el.setAttribute('data-theme', theme));
      
      // Fallback if no container found or just body needed
      if (allBackups.length === 0 && trinityBackup) {
          trinityBackup.setAttribute('data-theme', theme);
      }

      setBodyThemeClass(theme);
      saveTheme(theme);
      
      themeSwitcher.querySelectorAll('.trinity-theme-btn').forEach(b => {
        b.classList.toggle('active', b === btn);
      });
    });
  }

  const startButton = document.getElementById('trinity-backup-start');
  const importButton = document.getElementById('trinity-backup-import-start');
  const importInput = document.getElementById('trinity-backup-import-file');
  const downloadEl = document.getElementById('trinity-backup-download');
  
  // Export feedback elements
  const exportFeedback = document.getElementById('trinity-export-feedback');
  const exportStatusEl = document.getElementById('trinity-export-status');
  const exportProgressEl = document.getElementById('trinity-export-progress');
  const exportLogEl = document.getElementById('trinity-export-log');
  const exportSpinner = document.getElementById('trinity-export-spinner');
  
  // Import feedback elements
  const importFeedback = document.getElementById('trinity-import-feedback');
  const importStatusEl = document.getElementById('trinity-import-status');
  const importProgressEl = document.getElementById('trinity-import-progress');
  const importLogEl = document.getElementById('trinity-import-log');
  const importSpinner = document.getElementById('trinity-import-spinner');
  
  // Dropzone
  const dropzone = document.getElementById('trinity-dropzone');
  
  // Export options
  const optNoMedia = document.getElementById('trinity-opt-no-media');
  const optNoPlugins = document.getElementById('trinity-opt-no-plugins');
  const optNoThemes = document.getElementById('trinity-opt-no-themes');
  const optNoDatabase = document.getElementById('trinity-opt-no-database');
  const optNoSpam = document.getElementById('trinity-opt-no-spam');
  const optNoEmailReplace = document.getElementById('trinity-opt-no-email-replace');
  const optEncrypt = document.getElementById('trinity-opt-encrypt');
  const optPassword = document.getElementById('trinity-opt-password');
  const optPasswordConfirm = document.getElementById('trinity-opt-password-confirm');
  const passwordFields = document.getElementById('trinity-password-fields');
  const optAutoDownload = document.getElementById('trinity-opt-auto-download');
  
  // Backup list
  const backupsList = document.getElementById('trinity-backups-list');
  const refreshBackupsBtn = document.getElementById('trinity-refresh-backups');
  const cleanupBtn = document.getElementById('trinity-cleanup-jobs');

  if (!startButton) {
    return;
  }

  let running = false;
  let currentMode = null; // 'export' or 'import'

  // Warn user if they try to close/navigate away during a running job.
  // Browsers ignore custom text, but showing the confirmation prompt is enough.
  window.addEventListener('beforeunload', (e) => {
    if (!running) {
      return;
    }
    e.preventDefault();
    e.returnValue = '';
    return '';
  });

  const setUiRunning = (isRunning) => {
    // Main entry points
    if (startButton) startButton.disabled = isRunning;
    if (importButton) importButton.disabled = isRunning;

    // Existing Backups controls
    if (refreshBackupsBtn) refreshBackupsBtn.disabled = isRunning;
    if (cleanupBtn) cleanupBtn.disabled = isRunning;
    document.querySelectorAll('.trinity-restore-backup, .trinity-delete-backup').forEach((btn) => {
      btn.disabled = isRunning;
    });
    
    // Delete All Backups button
    const deleteAllBtn = document.getElementById('trinity-delete-all-backups');
    if (deleteAllBtn) deleteAllBtn.disabled = isRunning;
    
    // Schedule controls
    const schedFreq = document.getElementById('trinity-schedule-frequency');
    const schedTimeEl = document.getElementById('trinity-schedule-time');
    const schedRetEl = document.getElementById('trinity-schedule-retention');
    const schedSaveEl = document.getElementById('trinity-schedule-save');
    if (schedFreq) schedFreq.disabled = isRunning;
    if (schedTimeEl) schedTimeEl.disabled = isRunning;
    if (schedRetEl) schedRetEl.disabled = isRunning;
    if (schedSaveEl) schedSaveEl.disabled = isRunning;

    // Email & White Label save buttons
    const emailSaveEl = document.getElementById('trinity-email-save');
    const wlSaveEl = document.getElementById('trinity-wl-save');
    if (emailSaveEl) emailSaveEl.disabled = isRunning;
    if (wlSaveEl) wlSaveEl.disabled = isRunning;

    // Export options
    [
      optNoMedia,
      optNoPlugins,
      optNoThemes,
      optNoDatabase,
      optNoSpam,
      optNoEmailReplace,
      optEncrypt,
      optPassword,
      optPasswordConfirm,
      optAutoDownload,
    ].forEach((el) => {
      if (el) el.disabled = isRunning;
    });

    // Import controls
    if (importInput) importInput.disabled = isRunning;
    if (dropzone) {
      dropzone.style.pointerEvents = isRunning ? 'none' : '';
      dropzone.style.opacity = isRunning ? '0.65' : '';
    }
  };

  const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

  // Feedback helpers for export/import
  const showFeedback = (mode) => {
    if (mode === 'export' && exportFeedback) {
      exportFeedback.style.display = 'block';
    } else if (mode === 'import' && importFeedback) {
      importFeedback.style.display = 'block';
    }
  };

  const hideFeedback = (mode) => {
    if (mode === 'export' && exportFeedback) {
      exportFeedback.style.display = 'none';
    } else if (mode === 'import' && importFeedback) {
      importFeedback.style.display = 'none';
    }
  };

  const showSpinner = () => {
    if (currentMode === 'export' && exportSpinner) {
      exportSpinner.style.display = 'inline-block';
    } else if (currentMode === 'import' && importSpinner) {
      importSpinner.style.display = 'inline-block';
    }
  };

  const hideSpinner = () => {
    if (exportSpinner) exportSpinner.style.display = 'none';
    if (importSpinner) importSpinner.style.display = 'none';
  };

  const setStatus = (text, type = '') => {
    let statusEl = null;
    if (currentMode === 'export' && exportStatusEl) {
      statusEl = exportStatusEl;
    } else if (currentMode === 'import' && importStatusEl) {
      statusEl = importStatusEl;
    }
    if (statusEl) {
      statusEl.textContent = text;
      statusEl.classList.remove('trinity-text-success', 'trinity-text-danger');
      if (type === 'success') statusEl.classList.add('trinity-text-success');
      else if (type === 'error') statusEl.classList.add('trinity-text-danger');
    }
  };

  const setProgress = (value) => {
    if (currentMode === 'export' && exportProgressEl) {
      exportProgressEl.style.width = `${value}%`;
    } else if (currentMode === 'import' && importProgressEl) {
      importProgressEl.style.width = `${value}%`;
    }
  };

  const setLog = (text) => {
    if (currentMode === 'export' && exportLogEl) {
      exportLogEl.textContent = text;
    } else if (currentMode === 'import' && importLogEl) {
      importLogEl.textContent = text;
    }
  };

  const getLogEl = () => {
    return currentMode === 'export' ? exportLogEl : importLogEl;
  };

  const showReloginButton = (newUrl = null) => {
    const logEl = getLogEl();
    if (!logEl || document.getElementById('trinity-backup-relogin')) {
      return;
    }
    
    const btn = document.createElement('a');
    btn.id = 'trinity-backup-relogin';
    
    if (newUrl) {
      btn.href = newUrl + '/wp-admin/';
      btn.innerHTML = '<img src="' + (TrinityBackup?.assetUrl || '') + 'img/icon-update.svg" class="trinity-icon" alt=""> <span>Go to restored site</span>';
    } else {
      btn.href = TrinityBackup.loginUrl || '/wp-login.php';
      btn.innerHTML = '<img src="' + (TrinityBackup?.assetUrl || '') + 'img/icon-lock.svg" class="trinity-icon" alt=""> <span>Log in to restored site</span>';
    }

    btn.className = 'trinity-btn trinity-btn--primary';
    btn.target = '_blank';
    btn.rel = 'noopener noreferrer';
    logEl.parentNode.insertBefore(btn, logEl.nextSibling);
  };

  const post = async (action, payload) => {
    const params = new URLSearchParams();
    params.append('action', action);
    params.append('nonce', TrinityBackup.nonce);

    Object.entries(payload).forEach(([key, value]) => {
      if (value !== undefined && value !== null) {
        params.append(key, value);
      }
    });

    const response = await fetch(TrinityBackup.ajaxUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
      },
      body: params.toString(),
    });

    return response.json();
  };

  const postForm = async (action, formData) => {
    formData.append('action', action);
    formData.append('nonce', TrinityBackup.nonce);

    const response = await fetch(TrinityBackup.ajaxUrl, {
      method: 'POST',
      body: formData,
    });

    return response.json();
  };

  // === OPERATION LOCK (server-side concurrency guard) ===
  let activeOperationLock = null;
  let operationLockPollTimer = null;
  let lastOperationName = '';
  let scheduledToastEl = null;
  let scheduledToastHideTimer = null;

  const getOperationLockText = (lock) => {
    const op = lock?.operation ? String(lock.operation) : 'operation';
    if (op === 'scheduled_backup') return 'Scheduled backup is currently running. Please wait.';
    if (op === 'restore') return 'Restore is currently running. Please wait.';
    if (op === 'manual_backup') return 'Backup is currently running. Please wait.';
    if (op === 'delete_all') return 'Deleting backups is currently running. Please wait.';
    if (op === 'delete_backup') return 'Deleting a backup is currently running. Please wait.';
    return `Another operation is currently running (${op}). Please wait.`;
  };

  const ensureScheduledToastEl = () => {
    if (scheduledToastEl) return scheduledToastEl;

    const existing = document.getElementById('trinity-toast-scheduled');
    if (existing) {
      scheduledToastEl = existing;
      return scheduledToastEl;
    }

    const el = document.createElement('div');
    el.id = 'trinity-toast-scheduled';
    el.className = 'trinity-toast trinity-toast--warning';
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');
    el.innerHTML = `
      <div class="trinity-toast__icon" aria-hidden="true">
        <span class="trinity-spinner trinity-toast__spinner"></span>
        <svg class="trinity-toast__check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M20 6L9 17l-5-5" />
        </svg>
      </div>
      <div class="trinity-toast__content">
        <div class="trinity-toast__title">Scheduled backup running</div>
        <div class="trinity-toast__desc">Please wait until it finishes.</div>
      </div>
    `;
    const themeRoot = document.querySelector('.trinity-backup') || document.body;
    themeRoot.appendChild(el);
    scheduledToastEl = el;
    return scheduledToastEl;
  };

  const showScheduledToast = () => {
    const el = ensureScheduledToastEl();
    if (!el) return;
    if (scheduledToastHideTimer) {
      clearTimeout(scheduledToastHideTimer);
      scheduledToastHideTimer = null;
    }
    el.classList.remove('trinity-toast--success');
    el.classList.add('trinity-toast--warning');
    el.classList.add('is-spinning');
    el.classList.add('is-visible');
  };

  const hideScheduledToast = () => {
    if (!scheduledToastEl) return;
    scheduledToastEl.classList.remove('is-visible');
  };

  const showScheduledFinishedToast = () => {
    const el = ensureScheduledToastEl();
    if (!el) return;

    if (scheduledToastHideTimer) {
      clearTimeout(scheduledToastHideTimer);
      scheduledToastHideTimer = null;
    }

    el.classList.remove('trinity-toast--warning');
    el.classList.add('trinity-toast--success');
    el.classList.remove('is-spinning');
    const title = el.querySelector('.trinity-toast__title');
    const desc = el.querySelector('.trinity-toast__desc');
    if (title) title.textContent = 'Scheduled backup finished';
    if (desc) desc.textContent = 'Backup completed successfully.';
    el.classList.add('is-visible');

    scheduledToastHideTimer = setTimeout(() => {
      hideScheduledToast();
      // Restore default text for next run
      if (title) title.textContent = 'Scheduled backup running';
      if (desc) desc.textContent = 'Please wait until it finishes.';
      scheduledToastHideTimer = null;
    }, 4000);
  };

  const applyOperationLockToBackupButtons = () => {
    const locked = !!activeOperationLock;
    const title = locked ? getOperationLockText(activeOperationLock) : '';

    document.querySelectorAll('.trinity-restore-backup, .trinity-delete-backup').forEach((btn) => {
      btn.disabled = locked;
      if (locked) {
        btn.title = title;
      } else {
        btn.removeAttribute('title');
      }
    });

    // Also block "Delete All" entrypoint when any operation is running.
    if (deleteAllBackupsBtn) {
      deleteAllBackupsBtn.disabled = locked;
      if (locked) {
        deleteAllBackupsBtn.title = title;
      } else {
        deleteAllBackupsBtn.removeAttribute('title');
      }
    }

    // Block schedule controls while any operation is running.
    if (scheduleSaveBtn) {
      scheduleSaveBtn.disabled = locked;
      if (locked) {
        scheduleSaveBtn.title = title;
      } else {
        scheduleSaveBtn.removeAttribute('title');
      }
    }
    if (scheduleFrequency) scheduleFrequency.disabled = locked;
    if (scheduleTime) scheduleTime.disabled = locked;
    if (scheduleRetention) scheduleRetention.disabled = locked;

    // Block Start Export + Cleanup Temp Files while any operation is running.
    // (e.g. scheduled backup should block manual export/cleanup)
    if (startButton) {
      startButton.disabled = locked || running;
      if (locked) startButton.title = title;
      else startButton.removeAttribute('title');
    }
    if (cleanupBtn) {
      cleanupBtn.disabled = locked || running;
      if (locked) cleanupBtn.title = title;
      else cleanupBtn.removeAttribute('title');
    }

    // Block Import entry points (dropzone + file input + Start Import) while any operation is running.
    if (importButton) {
      importButton.disabled = locked || running;
      if (locked) importButton.title = title;
      else importButton.removeAttribute('title');
    }
    if (importInput) {
      importInput.disabled = locked || running;
      if (locked) importInput.title = title;
      else importInput.removeAttribute('title');
    }
    if (dropzone) {
      dropzone.style.pointerEvents = locked || running ? 'none' : '';
      dropzone.style.opacity = locked || running ? '0.65' : '';
      if (locked) dropzone.title = title;
      else dropzone.removeAttribute('title');
    }
  };

  const setOperationLock = (lock) => {
    const prevOp = lastOperationName;
    activeOperationLock = lock || null;
    lastOperationName = activeOperationLock?.operation ? String(activeOperationLock.operation) : '';

    applyOperationLockToBackupButtons();

    // Animated toast for scheduled backup.
    if (lastOperationName === 'scheduled_backup') {
      showScheduledToast();
    } else if (prevOp === 'scheduled_backup') {
      showScheduledFinishedToast();

      // After scheduled backup ends, refresh list + schedule info.
      // This keeps the UI accurate without requiring a manual "Refresh List".
      if (!running) {
        setTimeout(() => {
          if (!running) {
            loadBackupsList();
            // Pull fresh schedule info (next run) after it has been rescheduled.
            loadScheduleSettings();
          }
        }, 800);
      }
    }
  };

  const refreshOperationLock = async () => {
    try {
      const response = await post('trinity_backup_get_settings', {});
      if (response?.status === 'ok') {
        setOperationLock(response.operation_lock || null);

        // Keep only the "Next scheduled backup" text fresh while user stays on page.
        // Avoid overwriting form inputs; just update the info row.
        if (response.schedule) {
          updateScheduleInfo(response.schedule);
        }
      }
    } catch (err) {
      // Ignore polling errors.
    }

    return activeOperationLock;
  };

  const startOperationLockPolling = () => {
    if (operationLockPollTimer) return;
    // Quick initial refresh so UI is correct on page load.
    refreshOperationLock();
    operationLockPollTimer = setInterval(refreshOperationLock, 5000);
  };

  const ensureNoOperationLock = async () => {
    const lock = await refreshOperationLock();
    if (lock) {
      alert(getOperationLockText(lock));
      return false;
    }
    return true;
  };

  /**
   * Upload file in chunks for large files.
   * @param {File} file - The file to upload
   * @param {function} onProgress - Progress callback (0-100)
   * @returns {Promise<{status: string, path: string, url: string}>}
   */
  const uploadFileChunked = async (file, onProgress) => {
    const CHUNK_SIZE = 512 * 1024; // 512KB chunks
    const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
    const uploadId = 'upload_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    
    for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
      const start = chunkIndex * CHUNK_SIZE;
      const end = Math.min(start + CHUNK_SIZE, file.size);
      const chunk = file.slice(start, end);
      
      const formData = new FormData();
      formData.append('action', 'trinity_backup_upload_chunk');
      formData.append('nonce', TrinityBackup.nonce);
      formData.append('chunk', chunk);
      formData.append('filename', file.name);
      formData.append('chunk_index', chunkIndex);
      formData.append('total_chunks', totalChunks);
      formData.append('upload_id', uploadId);
      
      const response = await fetch(TrinityBackup.ajaxUrl, {
        method: 'POST',
        body: formData,
      });
      
      const result = await response.json();
      
      if (result.success === false) {
        throw new Error(result.data?.message || 'Chunk upload failed');
      }
      
      if (onProgress) {
        const progress = Math.round(((chunkIndex + 1) / totalChunks) * 100);
        onProgress(progress);
      }
      
      // If last chunk, return the final result
      if (result.status === 'ok') {
        return result;
      }
    }
    
    throw new Error('Upload incomplete');
  };

  const handleError = (message) => {
    setStatus('Error', 'error');
    setLog(message || 'Unexpected error.');
    hideSpinner();
    running = false;
    currentMode = null;
    setUiRunning(false);
  };

  const formatBytes = (bytes) => {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
  };

  const formatDate = (timestamp) => {
    const date = new Date(timestamp * 1000);
    return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
  };

  // === EXPORT OPTIONS ===
  const getExportOptions = () => {
    const options = {};
    if (optNoMedia?.checked) options.no_media = '1';
    if (optNoPlugins?.checked) options.no_plugins = '1';
    if (optNoThemes?.checked) options.no_themes = '1';
    if (optNoDatabase?.checked) options.no_database = '1';
    if (optNoSpam?.checked) options.no_spam_comments = '1';
    if (optNoEmailReplace?.checked) options.no_email_replace = '1';
    if (optEncrypt?.checked && optPassword?.value) {
      options.password = optPassword.value;
    }
    return options;
  };

  const validateExportOptions = () => {
    if (optEncrypt?.checked) {
      const password = optPassword?.value || '';
      const confirm = optPasswordConfirm?.value || '';
      
      if (!password) {
        return 'Please enter a password for encryption.';
      }
      if (password.length < 4) {
        return 'Password must be at least 4 characters.';
      }
      if (password !== confirm) {
        return 'Passwords do not match.';
      }
    }
    return null;
  };

  // === IMPORT OPTIONS ===
  const getImportOptions = () => {
    // URL replacement is now automatic based on _site_info.json in archive
    return {};
  };

  // === IMPORT CONFIRMATION MODAL ===
  const showImportConfirmation = () => {
    return new Promise((resolve) => {
      const existing = document.getElementById('trinity-confirm-modal');
      if (existing) existing.remove();

      const modal = document.createElement('div');
      modal.id = 'trinity-confirm-modal';
      modal.setAttribute('data-theme', getCurrentTheme());
      modal.innerHTML = `
        <div class="trinity-modal-overlay">
          <div class="trinity-modal-content">
            <h3>Confirm Import</h3>
            <p><strong>Warning:</strong> Importing a backup will <em>replace</em> your current site content.</p>
            <ul>
              <li>All existing database content will be overwritten</li>
              <li>Files in wp-content will be replaced</li>
              <li>You may need to log in again after import</li>
            </ul>
            <p>Are you sure you want to proceed?</p>
            <div class="trinity-modal-buttons">
              <button type="button" class="button trinity-modal-cancel">Cancel</button>
              <button type="button" class="button button-primary trinity-modal-confirm">Yes, Import</button>
            </div>
          </div>
        </div>
      `;

      document.body.appendChild(modal);

      modal.querySelector('.trinity-modal-cancel').addEventListener('click', () => {
        modal.remove();
        resolve(false);
      });

      modal.querySelector('.trinity-modal-confirm').addEventListener('click', () => {
        modal.remove();
        resolve(true);
      });

      modal.querySelector('.trinity-modal-overlay').addEventListener('click', (e) => {
        if (e.target.classList.contains('trinity-modal-overlay')) {
          modal.remove();
          resolve(false);
        }
      });
    });
  };

  // === PASSWORD PROMPT MODAL ===
  const showPasswordPrompt = (errorMessage = null, attemptsLeft = null) => {
    return new Promise((resolve) => {
      const existing = document.getElementById('trinity-password-modal');
      if (existing) existing.remove();

      const modal = document.createElement('div');
      modal.id = 'trinity-password-modal';
      modal.setAttribute('data-theme', getCurrentTheme());
      modal.innerHTML = `
        <div class="trinity-modal-overlay">
          <div class="trinity-modal-content">
            <h3>Password Required</h3>
            <p>This archive is password-protected. Please enter the password to continue.</p>
            ${errorMessage ? `<p class="trinity-password-error" style="color: var(--trinity-danger, #d63638); font-weight: 500;">${errorMessage}</p>` : ''}
            <div class="trinity-password-input-row" style="margin: 15px 0; position: relative; display: inline-block; width: 100%;">
              <input type="text" id="trinity-archive-password" placeholder="Enter archive password" 
                     style="width: 100%; padding: 8px; padding-right: 35px; font-size: 14px; box-sizing: border-box;" autocomplete="off" />
              <span class="trinity-toggle-password-modal" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); cursor: pointer; user-select: none;" title="Hide password">
                <img src="${TrinityBackup?.assetUrl || ''}img/icon-visibility-off.svg" class="trinity-icon" style="width: 20px; height: 20px;" alt="">
              </span>
            </div>
            <div class="trinity-modal-buttons">
              <button type="button" class="button trinity-modal-cancel">Cancel</button>
              <button type="button" class="button button-primary trinity-modal-confirm">Unlock Archive</button>
            </div>
          </div>
        </div>
      `;

      document.body.appendChild(modal);
      
      const passwordInput = modal.querySelector('#trinity-archive-password');
      const toggleBtn = modal.querySelector('.trinity-toggle-password-modal');
      const toggleImg = toggleBtn.querySelector('img');
      passwordInput.focus();

      // Toggle password visibility
      toggleBtn.addEventListener('click', () => {
        if (passwordInput.type === 'text') {
          passwordInput.type = 'password';
          if (toggleImg) toggleImg.src = toggleImg.src.replace('icon-visibility-off.svg', 'icon-visibility.svg');
          toggleBtn.title = 'Show password';
        } else {
          passwordInput.type = 'text';
          if (toggleImg) toggleImg.src = toggleImg.src.replace('icon-visibility.svg', 'icon-visibility-off.svg');
          toggleBtn.title = 'Hide password';
        }
      });

      const submitPassword = () => {
        const password = passwordInput.value;
        modal.remove();
        resolve(password || null);
      };

      modal.querySelector('.trinity-modal-cancel').addEventListener('click', () => {
        modal.remove();
        resolve(null);
      });

      modal.querySelector('.trinity-modal-confirm').addEventListener('click', submitPassword);

      passwordInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          submitPassword();
        }
      });

      modal.querySelector('.trinity-modal-overlay').addEventListener('click', (e) => {
        if (e.target.classList.contains('trinity-modal-overlay')) {
          modal.remove();
          resolve(null);
        }
      });
    });
  };

  // === BACKUPS LIST ===
  const loadBackupsList = async () => {
    if (!backupsList) return;

    backupsList.innerHTML = '<tr><td colspan="5">Loading...</td></tr>';

    try {
      const response = await post('trinity_backup_list_backups', {});
      
      if (!response.success || !response.backups) {
        backupsList.innerHTML = '<tr><td colspan="5">Failed to load backups</td></tr>';
        applyOperationLockToBackupButtons();
        return;
      }

      if (response.backups.length === 0) {
        backupsList.innerHTML = '<tr><td colspan="5">No backups found</td></tr>';
        applyOperationLockToBackupButtons();
        return;
      }

      const iconBase = TrinityBackup?.imgUrl || ((TrinityBackup?.assetUrl || '') + 'img/');

      backupsList.innerHTML = response.backups.map(backup => `
        <tr data-id="${backup.id}" data-file="${backup.filename}" data-path="${backup.path}">
          <td><a href="${backup.url}" download>${backup.filename}</a></td>
          <td>${formatBytes(backup.size)}</td>
          <td>${backup.created_formatted ? backup.created_formatted : formatDate(backup.created)}</td>
          <td>
            <span class="trinity-badge ${backup.origin === 'scheduled' ? 'trinity-badge--scheduled' : (backup.origin === 'pre_update' ? 'trinity-badge--preupdate' : 'trinity-badge--manual')}">
              ${backup.origin === 'scheduled' ? 'Scheduled' : (backup.origin === 'pre_update' ? 'Pre-Update' : 'Manual')}
            </span>
          </td>
          <td>
            <div class="trinity-backup__row-actions">
              <a href="${backup.url}" download class="trinity-btn trinity-btn--secondary trinity-btn--sm">
                <img src="${iconBase}icon-export.svg" class="trinity-icon" alt=""> Download
              </a>
              <button type="button" class="trinity-btn trinity-btn--primary trinity-btn--sm trinity-restore-backup" 
                      data-file="${backup.filename}" data-path="${backup.path}">
                <img src="${iconBase}icon-restore.svg" class="trinity-icon" alt=""> Restore
              </button>
              <button type="button" class="trinity-btn trinity-btn--secondary trinity-btn--sm trinity-delete-backup" 
                      data-id="${backup.id}" data-file="${backup.filename}">
                <img src="${iconBase}icon-delete.svg" class="trinity-icon" alt=""> Delete
              </button>
            </div>
          </td>
        </tr>
      `).join('');

      // Add delete handlers
      backupsList.querySelectorAll('.trinity-delete-backup').forEach(btn => {
        btn.addEventListener('click', async (e) => {
          if (!(await ensureNoOperationLock())) {
            return;
          }
          const backupId = e.target.dataset.id;
          const filename = e.target.dataset.file;
          if (!confirm(`Delete backup "${filename}"?`)) return;

          const result = await post('trinity_backup_delete', { id: backupId, filename });
          if (result.success) {
            e.target.closest('tr').remove();
          } else {
            alert(result.data?.message || result.message || 'Failed to delete backup');
          }
        });
      });

      // Add restore handlers
      backupsList.querySelectorAll('.trinity-restore-backup').forEach(btn => {
        btn.addEventListener('click', async (e) => {
          if (!(await ensureNoOperationLock())) {
            return;
          }
          if (running) {
            alert('Another operation is in progress.');
            return;
          }

          const filename = e.target.dataset.file;
          const archivePath = e.target.dataset.path;

          // Show confirmation modal
          const confirmed = await showImportConfirmation();
          if (!confirmed) return;

          running = true;
          currentMode = 'import';
          showFeedback('import');
          showSpinner();
          setUiRunning(true);
          downloadEl.style.display = 'none';
          setProgress(0);
          setStatus('Checking archive');
          setLog(`Preparing to restore ${filename}...`);

          // Check if archive is encrypted
          let archivePassword = '';
          const checkResponse = await post('trinity_backup_check_archive', {
            archive_path: archivePath
          });

          if (checkResponse.success === false) {
            handleError(checkResponse.data?.message || 'Failed to check archive');
            return;
          }

          if (checkResponse.encrypted && checkResponse.password_required) {
            // Archive is encrypted - ask for password
            let passwordValid = false;
            let errorMessage = null;
            let attempts = 0;
            const maxAttempts = 10;

            while (!passwordValid && attempts < maxAttempts) {
              attempts++;
              const attemptsLeft = maxAttempts - attempts;
              const enteredPassword = await showPasswordPrompt(errorMessage, attemptsLeft);
              
              if (enteredPassword === null) {
                handleError('Restore cancelled - password required.');
                return;
              }

              setLog('Verifying password...');
              const verifyResponse = await post('trinity_backup_check_archive', {
                archive_path: archivePath,
                password: enteredPassword
              });

              if (verifyResponse.success === false) {
                handleError(verifyResponse.data?.message || 'Failed to verify password');
                return;
              }

              if (verifyResponse.password_valid) {
                archivePassword = enteredPassword;
                passwordValid = true;
              } else {
                errorMessage = `Incorrect password. ${attemptsLeft > 0 ? `${attemptsLeft} attempts remaining.` : 'Last attempt!'}`;
              }
            }

            if (!passwordValid) {
              handleError('Too many incorrect password attempts. Restore cancelled.');
              return;
            }
          }

          setStatus('Starting restore');
          setLog('Initializing restore...');
          setProgress(25);

          const importOptions = getImportOptions();
          if (archivePassword) {
            importOptions.password = archivePassword;
          }

          const startResponse = await post('trinity_backup_import_start', {
            archive_path: archivePath,
            ...importOptions
          });

          if (startResponse && startResponse.success === false) {
            handleError(startResponse.data?.message);
            return;
          }

          if (!startResponse || startResponse.status === 'error') {
            handleError(startResponse?.message);
            return;
          }

          if (!startResponse.job_id) {
            handleError('Missing import job id.');
            return;
          }

          await runLoop(startResponse.job_id, 'trinity_backup_import_run');
        });
      });

      // If an operation is active, disable the newly rendered buttons.
      applyOperationLockToBackupButtons();

    } catch (err) {
      backupsList.innerHTML = '<tr><td colspan="5">Error loading backups</td></tr>';
      applyOperationLockToBackupButtons();
    }
  };

  // === CLEANUP TEMP FILES ===
  const cleanupTempFiles = async () => {
    if (!(await ensureNoOperationLock())) {
      return;
    }
    if (!confirm('Clean up temporary files from incomplete backup jobs?')) return;

    cleanupBtn.disabled = true;
    cleanupBtn.textContent = 'Cleaning...';

    try {
      const response = await post('trinity_backup_cleanup', {});
      if (response.success) {
        alert(`Cleaned up ${response.cleaned} temporary folders.`);
      } else {
        alert(response.message || 'Cleanup failed');
      }
    } catch (err) {
      alert('Cleanup failed');
    }

    cleanupBtn.disabled = false;
    cleanupBtn.innerHTML = '<img src="' + TrinityBackup.assetUrl + 'img/icon-cleanup.svg" class="trinity-icon" alt=""> Cleanup Temp Files';
  };

  // === DELETE ALL BACKUPS ===
  const deleteAllBackupsBtn = document.getElementById('trinity-delete-all-backups');

  const deleteAllBackups = async () => {
    const existing = document.getElementById('trinity-delete-all-modal');
    if (existing) existing.remove();

    const modal = document.createElement('div');
    modal.id = 'trinity-delete-all-modal';
    modal.setAttribute('data-theme', getCurrentTheme());
    modal.innerHTML = `
      <div class="trinity-modal-overlay">
        <div class="trinity-modal-content">
          <h3 class="trinity-modal-title--danger">Delete All Backups</h3>
          <div id="trinity-delete-all-form">
            <p><strong>Warning:</strong> This will permanently delete ALL backup files.</p>
            <p>This action cannot be undone!</p>
            <p style="margin-top: 15px;">Type <strong>DELETE</strong> to confirm:</p>
            <input type="text" id="trinity-delete-confirm-input" placeholder="Type DELETE" 
                   style="width: 100%; padding: 8px; margin: 10px 0; font-size: 14px;" autocomplete="off" />
            <div class="trinity-modal-buttons">
              <button type="button" class="button trinity-modal-cancel">Cancel</button>
              <button type="button" class="trinity-btn trinity-btn--secondary trinity-btn--sm" 
                      id="trinity-delete-all-confirm" disabled>Delete All</button>
            </div>
          </div>
          <div id="trinity-delete-all-progress" style="display: none; text-align: center; padding: 20px 0;">
            <span class="spinner is-active" style="float: none; margin: 0 auto 15px;"></span>
            <p style="margin: 0; font-weight: 500;">Deleting all backups...</p>
            <p style="margin: 10px 0 0; color: #646970;">Please wait, do not close this window.</p>
          </div>
          <div id="trinity-delete-all-result" style="display: none; text-align: center; padding: 20px 0;">
            <p id="trinity-delete-all-message" style="font-size: 16px; margin: 0 0 15px;"></p>
            <button type="button" class="button button-primary trinity-modal-close">Close</button>
          </div>
        </div>
      </div>
    `;

    document.body.appendChild(modal);

    const input = modal.querySelector('#trinity-delete-confirm-input');
    const confirmBtn = modal.querySelector('#trinity-delete-all-confirm');
    const formSection = modal.querySelector('#trinity-delete-all-form');
    const progressSection = modal.querySelector('#trinity-delete-all-progress');
    const resultSection = modal.querySelector('#trinity-delete-all-result');
    const resultMessage = modal.querySelector('#trinity-delete-all-message');

    input.addEventListener('input', () => {
      confirmBtn.disabled = input.value !== 'DELETE';
    });

    modal.querySelector('.trinity-modal-cancel').addEventListener('click', () => {
      modal.remove();
    });

    modal.querySelector('.trinity-modal-overlay').addEventListener('click', (e) => {
      if (e.target.classList.contains('trinity-modal-overlay') && formSection.style.display !== 'none') {
        modal.remove();
      }
    });

    modal.querySelector('.trinity-modal-close').addEventListener('click', () => {
      modal.remove();
      loadBackupsList();
    });

    confirmBtn.addEventListener('click', async () => {
      if (input.value !== 'DELETE') return;

      // Show progress
      formSection.style.display = 'none';
      progressSection.style.display = 'block';

      try {
        const response = await post('trinity_backup_delete_all', {});
        progressSection.style.display = 'none';
        resultSection.style.display = 'block';
        
        if (response.success) {
          resultMessage.innerHTML = `<span class="trinity-text-success">Successfully deleted <strong>${response.deleted}</strong> backup(s).</span>`;
        } else {
          resultMessage.innerHTML = `<span class="trinity-text-danger">${response.data?.message || response.message || 'Failed to delete backups'}</span>`;
        }
      } catch (err) {
        progressSection.style.display = 'none';
        resultSection.style.display = 'block';
        resultMessage.innerHTML = `<span class="trinity-text-danger">${err?.message || 'Failed to delete backups'}</span>`;
      }
    });

    input.focus();
  };

  if (deleteAllBackupsBtn) {
    deleteAllBackupsBtn.addEventListener('click', deleteAllBackups);
  }

  // === SCHEDULED BACKUPS ===
  const scheduleFrequency = document.getElementById('trinity-schedule-frequency');
  const scheduleTime = document.getElementById('trinity-schedule-time');
  const scheduleTimeRow = document.getElementById('trinity-schedule-time-row');
  const scheduleRetention = document.getElementById('trinity-schedule-retention');
  const scheduleSaveBtn = document.getElementById('trinity-schedule-save');
  const scheduleStatus = document.getElementById('trinity-schedule-status');
  const scheduleInfo = document.getElementById('trinity-schedule-info');
  const scheduleNext = document.getElementById('trinity-schedule-next');

  // === PRE-UPDATE BACKUPS SETTINGS ===
  const preupdateEnabled = document.getElementById('trinity-preupdate-enabled');
  const preupdateOptions = document.getElementById('trinity-preupdate-options');
  const preupdateBlockUpdates = document.getElementById('trinity-preupdate-block-updates');
  const preupdateNoMedia = document.getElementById('trinity-preupdate-no-media');
  const preupdateNoPlugins = document.getElementById('trinity-preupdate-no-plugins');
  const preupdateNoThemes = document.getElementById('trinity-preupdate-no-themes');
  const preupdateNoDatabase = document.getElementById('trinity-preupdate-no-database');
  const preupdateNoSpam = document.getElementById('trinity-preupdate-no-spam');
  const preupdateNoEmailReplace = document.getElementById('trinity-preupdate-no-email-replace');
  const preupdateSaveBtn = document.getElementById('trinity-preupdate-save');
  const preupdateStatus = document.getElementById('trinity-preupdate-status');

  // Toggle dependent options visibility/disabled state
  const updatePreupdateOptionsState = () => {
    if (!preupdateOptions || !preupdateEnabled) return;
    const enabled = preupdateEnabled.checked;
    preupdateOptions.setAttribute('data-disabled', enabled ? 'false' : 'true');
  };

  if (preupdateEnabled) {
    preupdateEnabled.addEventListener('change', updatePreupdateOptionsState);
  };

  // Frequencies that require time selection (daily/weekly)
  const timeBasedFrequencies = ['daily', 'weekly'];
  
  const updateTimeRowVisibility = () => {
    if (!scheduleFrequency || !scheduleTimeRow) return;
    const freq = scheduleFrequency.value;
    scheduleTimeRow.style.display = timeBasedFrequencies.includes(freq) ? 'flex' : 'none';
  };

  const loadScheduleSettings = async () => {
    if (!scheduleFrequency) return;

    try {
      const response = await post('trinity_backup_get_settings', {});
      if (response.status === 'ok' && response.schedule) {
        if (Object.prototype.hasOwnProperty.call(response, 'operation_lock')) {
          setOperationLock(response.operation_lock || null);
        }
        const schedule = response.schedule;
        
        // Set frequency
        if (schedule.frequency) {
          scheduleFrequency.value = schedule.frequency;
        }
        
        // Set time (find closest hour)
        if (schedule.time) {
          const hour = parseInt(schedule.time.split(':')[0], 10);
          scheduleTime.value = String(hour).padStart(2, '0') + ':00';
        }
        
        // Set retention
        if (schedule.retention) {
          scheduleRetention.value = schedule.retention;
        }
        
        // Update time row visibility
        updateTimeRowVisibility();
        
        // Show next run info
        updateScheduleInfo(schedule);
      }

      if (response?.status === 'ok' && response.preupdate && preupdateEnabled) {
        const p = response.preupdate;
        preupdateEnabled.checked = !!p.enabled;
        if (preupdateBlockUpdates) preupdateBlockUpdates.checked = p.block_updates !== false;
        if (preupdateNoMedia) preupdateNoMedia.checked = !!p.no_media;
        if (preupdateNoPlugins) preupdateNoPlugins.checked = !!p.no_plugins;
        if (preupdateNoThemes) preupdateNoThemes.checked = !!p.no_themes;
        if (preupdateNoDatabase) preupdateNoDatabase.checked = !!p.no_database;
        if (preupdateNoSpam) preupdateNoSpam.checked = !!p.no_spam_comments;
        if (preupdateNoEmailReplace) preupdateNoEmailReplace.checked = !!p.no_email_replace;
        updatePreupdateOptionsState();
      }

      // Load email notification settings
      if (response?.status === 'ok' && response.email) {
        loadEmailSettings(response.email);
      }

      // Load white-label settings
      if (response?.status === 'ok' && response.whitelabel) {
        loadWhiteLabelSettings(response.whitelabel);
      }
    } catch (err) {
      console.error('Failed to load schedule settings:', err);
    }
  };

  const savePreUpdateSettings = async () => {
    if (!preupdateSaveBtn || !preupdateEnabled) return;

    preupdateSaveBtn.disabled = true;
    preupdateSaveBtn.textContent = 'Saving...';
    if (preupdateStatus) {
      preupdateStatus.textContent = '';
      preupdateStatus.style.color = '';
    }

    try {
      const response = await post('trinity_backup_preupdate_save', {
        enabled: preupdateEnabled.checked ? '1' : '0',
        block_updates: preupdateBlockUpdates?.checked ? '1' : '0',
        no_media: preupdateNoMedia?.checked ? '1' : '0',
        no_plugins: preupdateNoPlugins?.checked ? '1' : '0',
        no_themes: preupdateNoThemes?.checked ? '1' : '0',
        no_database: preupdateNoDatabase?.checked ? '1' : '0',
        no_spam_comments: preupdateNoSpam?.checked ? '1' : '0',
        no_email_replace: preupdateNoEmailReplace?.checked ? '1' : '0',
      });

      if (response.status === 'ok') {
        if (preupdateStatus) {
          preupdateStatus.innerHTML = '<span class="trinity-text-success">Settings saved!</span>';
          preupdateStatus.style.color = '#00a32a';
          setTimeout(() => {
            preupdateStatus.textContent = '';
          }, 3000);
        }
      } else {
        if (preupdateStatus) {
          preupdateStatus.innerHTML = '<span class="trinity-text-danger">' + (response.message || 'Failed to save') + '</span>';
          preupdateStatus.style.color = '#d63638';
        }
      }
    } catch (err) {
      if (preupdateStatus) {
        preupdateStatus.innerHTML = '<span class="trinity-text-danger">Error saving settings</span>';
        preupdateStatus.style.color = '#d63638';
      }
    }

    preupdateSaveBtn.disabled = false;
    preupdateSaveBtn.innerHTML = '<img src="' + (TrinityBackup?.assetUrl || '') + 'img/icon-save.svg" class="trinity-icon" alt=""> Save Settings';
  };

  const updateScheduleInfo = (schedule) => {
    if (!scheduleInfo || !scheduleNext) return;

    const nextRunText = schedule?.next_run_formatted ? String(schedule.next_run_formatted) : '';

    if (schedule.enabled && nextRunText) {
      scheduleInfo.style.display = 'flex';
      scheduleNext.textContent = nextRunText;
    } else {
      scheduleInfo.style.display = 'none';
      scheduleNext.textContent = '-';
    }
  };

  const saveScheduleSettings = async () => {
    if (!scheduleSaveBtn) return;

    const frequency = scheduleFrequency?.value || 'disabled';
    const time = scheduleTime?.value || '03:00';
    const retention = scheduleRetention?.value || '5';

    scheduleSaveBtn.disabled = true;
    scheduleSaveBtn.textContent = 'Saving...';
    scheduleStatus.textContent = '';
    scheduleStatus.style.color = '';

    try {
      const response = await post('trinity_backup_schedule', {
        frequency,
        time,
        retention
      });

      if (response.status === 'ok') {
        scheduleStatus.innerHTML = '<span class="trinity-text-success">Schedule saved!</span>';
        scheduleStatus.style.color = '#00a32a';

        // Hide schedule info immediately when disabling.
        // (Avoid requiring a full page reload if wp-cron state lags.)
        if (frequency === 'disabled') {
          updateScheduleInfo({ enabled: false });
        } else if (response.schedule) {
          updateScheduleInfo(response.schedule);
        }
        
        // Clear success message after 3 seconds
        setTimeout(() => {
          scheduleStatus.textContent = '';
        }, 3000);
      } else {
        scheduleStatus.innerHTML = '<span class="trinity-text-danger">' + (response.message || 'Failed to save') + '</span>';
        scheduleStatus.style.color = '#d63638';
      }
    } catch (err) {
      scheduleStatus.innerHTML = '<span class="trinity-text-danger">Error saving schedule</span>';
      scheduleStatus.style.color = '#d63638';
    }

    scheduleSaveBtn.disabled = false;
    scheduleSaveBtn.innerHTML = '<img src="' + (TrinityBackup?.assetUrl || '') + 'img/icon-save.svg" class="trinity-icon" alt=""> Save Schedule';
  };

  // Init schedule
  if (scheduleSaveBtn) {
    scheduleSaveBtn.addEventListener('click', saveScheduleSettings);
    loadScheduleSettings();
  }

  // Init pre-update settings
  if (preupdateSaveBtn) {
    preupdateSaveBtn.addEventListener('click', savePreUpdateSettings);
  }

  // === EMAIL NOTIFICATIONS SETTINGS ===
  const emailEnabled = document.getElementById('trinity-email-enabled');
  const emailOptions = document.getElementById('trinity-email-options');
  const emailRecipients = document.getElementById('trinity-email-recipients');
  const emailOnManual = document.getElementById('trinity-email-on-manual');
  const emailOnScheduled = document.getElementById('trinity-email-on-scheduled');
  const emailOnPreupdate = document.getElementById('trinity-email-on-preupdate');
  const emailOnImport = document.getElementById('trinity-email-on-import');
  const emailFailureOnly = document.getElementById('trinity-email-failure-only');
  const emailSaveBtn = document.getElementById('trinity-email-save');
  const emailTestBtn = document.getElementById('trinity-email-test');
  const emailStatus = document.getElementById('trinity-email-status');
  const emailTestStatus = document.getElementById('trinity-email-test-status');

  const updateEmailOptionsState = () => {
    if (!emailOptions || !emailEnabled) return;
    emailOptions.setAttribute('data-disabled', emailEnabled.checked ? 'false' : 'true');
  };

  if (emailEnabled) {
    emailEnabled.addEventListener('change', updateEmailOptionsState);
  }

  const loadEmailSettings = (data) => {
    if (!emailEnabled || !data) return;
    emailEnabled.checked = !!data.enabled;
    if (emailRecipients) emailRecipients.value = data.recipients || '';
    if (emailOnManual) emailOnManual.checked = !!data.on_manual;
    if (emailOnScheduled) emailOnScheduled.checked = data.on_scheduled !== false;
    if (emailOnPreupdate) emailOnPreupdate.checked = data.on_pre_update !== false;
    if (emailOnImport) emailOnImport.checked = data.on_import !== false;
    if (emailFailureOnly) emailFailureOnly.checked = !!data.on_failure_only;
    updateEmailOptionsState();
  };

  const saveEmailSettings = async () => {
    if (!emailSaveBtn || !emailEnabled) return;

    emailSaveBtn.disabled = true;
    emailSaveBtn.textContent = 'Saving...';
    if (emailStatus) { emailStatus.textContent = ''; emailStatus.style.color = ''; }

    try {
      const response = await post('trinity_backup_email_save', {
        enabled: emailEnabled.checked ? '1' : '0',
        recipients: emailRecipients?.value || '',
        on_manual: emailOnManual?.checked ? '1' : '0',
        on_scheduled: emailOnScheduled?.checked ? '1' : '0',
        on_pre_update: emailOnPreupdate?.checked ? '1' : '0',
        on_import: emailOnImport?.checked ? '1' : '0',
        on_failure_only: emailFailureOnly?.checked ? '1' : '0',
      });

      if (response.status === 'ok') {
        if (emailStatus) {
          emailStatus.innerHTML = '<span class="trinity-text-success">Settings saved!</span>';
          emailStatus.style.color = '#00a32a';
          setTimeout(() => { emailStatus.textContent = ''; }, 3000);
        }
      } else {
        if (emailStatus) {
          emailStatus.innerHTML = '<span class="trinity-text-danger">' + (response.message || 'Failed to save') + '</span>';
          emailStatus.style.color = '#d63638';
        }
      }
    } catch (err) {
      if (emailStatus) {
        emailStatus.innerHTML = '<span class="trinity-text-danger">Error saving settings</span>';
        emailStatus.style.color = '#d63638';
      }
    }

    emailSaveBtn.disabled = false;
    emailSaveBtn.innerHTML = '<img src="' + (TrinityBackup?.assetUrl || '') + 'img/icon-save.svg" class="trinity-icon" alt=""> Save Settings';
  };

  if (emailSaveBtn) {
    emailSaveBtn.addEventListener('click', saveEmailSettings);
  }

  const sendTestEmail = async () => {
    if (!emailTestBtn) return;

    emailTestBtn.disabled = true;
    emailTestBtn.textContent = 'Sending...';
    if (emailTestStatus) { emailTestStatus.textContent = ''; emailTestStatus.style.color = ''; }

    try {
      const response = await post('trinity_backup_email_test', {});

      if (response.status === 'ok') {
        if (emailTestStatus) {
          const to = Array.isArray(response.recipients) ? response.recipients.join(', ') : '';
          emailTestStatus.innerHTML = '<span class="trinity-text-success">Test email sent' + (to ? ' to ' + to : '') + '.</span>';
          emailTestStatus.style.color = '#00a32a';
          setTimeout(() => { emailTestStatus.textContent = ''; }, 5000);
        }
      } else {
        if (emailTestStatus) {
          emailTestStatus.innerHTML = '<span class="trinity-text-danger">' + (response.message || 'Failed to send test email') + '</span>';
          emailTestStatus.style.color = '#d63638';
        }
      }
    } catch (err) {
      if (emailTestStatus) {
        emailTestStatus.innerHTML = '<span class="trinity-text-danger">Error sending test email</span>';
        emailTestStatus.style.color = '#d63638';
      }
    }

    emailTestBtn.disabled = false;
    emailTestBtn.innerHTML = '<img src="' + (TrinityBackup?.assetUrl || '') + 'img/icon-export.svg" class="trinity-icon" alt=""> Send test email';
  };

  if (emailTestBtn) {
    emailTestBtn.addEventListener('click', sendTestEmail);
  }

  // === WHITE LABEL SETTINGS ===
  const wlEnabled = document.getElementById('trinity-wl-enabled');
  const wlOptions = document.getElementById('trinity-wl-options');
  const wlName = document.getElementById('trinity-wl-name');
  const wlDescription = document.getElementById('trinity-wl-description');
  const wlAuthor = document.getElementById('trinity-wl-author');
  const wlAuthorUrl = document.getElementById('trinity-wl-author-url');
  const wlIcon = document.getElementById('trinity-wl-icon');
  const wlHideBranding = document.getElementById('trinity-wl-hide-branding');
  const wlHideAccountMenu = document.getElementById('trinity-wl-hide-account-menu');
  const wlHideContactMenu = document.getElementById('trinity-wl-hide-contact-menu');
  const wlHideViewDetails = document.getElementById('trinity-wl-hide-view-details');
  const wlOnlyDeactivateAction = document.getElementById('trinity-wl-only-deactivate-action');
  const wlVisibleUser = document.getElementById('trinity-wl-visible-user');
  const wlUseCurrentUser = document.getElementById('trinity-wl-use-current-user');
  const wlSaveBtn = document.getElementById('trinity-wl-save');
  const wlStatus = document.getElementById('trinity-wl-status');

  const updateWlOptionsState = () => {
    if (!wlOptions || !wlEnabled) return;
    wlOptions.setAttribute('data-disabled', wlEnabled.checked ? 'false' : 'true');
  };

  if (wlEnabled) {
    wlEnabled.addEventListener('change', updateWlOptionsState);
  }

  if (wlUseCurrentUser && wlVisibleUser) {
    wlUseCurrentUser.addEventListener('click', () => {
      const uid = wlUseCurrentUser.getAttribute('data-current-user-id') || '0';
      wlVisibleUser.value = uid;
    });
  }

  const loadWhiteLabelSettings = (data) => {
    if (!wlEnabled || !data) return;
    wlEnabled.checked = !!data.enabled;
    if (wlName) wlName.value = data.plugin_name || '';
    if (wlDescription) wlDescription.value = data.plugin_description || '';
    if (wlAuthor) wlAuthor.value = data.author_name || '';
    if (wlAuthorUrl) wlAuthorUrl.value = data.author_url || '';
    if (wlIcon) wlIcon.value = data.menu_icon || '';
    if (wlHideBranding) wlHideBranding.checked = !!data.hide_branding;
    if (wlHideAccountMenu) wlHideAccountMenu.checked = !!data.hide_account_menu;
    if (wlHideContactMenu) wlHideContactMenu.checked = !!data.hide_contact_menu;
    if (wlHideViewDetails) wlHideViewDetails.checked = !!data.hide_view_details;
    if (wlOnlyDeactivateAction) wlOnlyDeactivateAction.checked = !!data.only_deactivate_action;
    if (wlVisibleUser) wlVisibleUser.value = String(data.visible_user_id || 0);
    updateWlOptionsState();
  };

  const saveWhiteLabelSettings = async () => {
    if (!wlSaveBtn || !wlEnabled) return;

    wlSaveBtn.disabled = true;
    wlSaveBtn.textContent = 'Saving...';
    if (wlStatus) { wlStatus.textContent = ''; wlStatus.style.color = ''; }

    try {
      const response = await post('trinity_backup_whitelabel_save', {
        enabled: wlEnabled.checked ? '1' : '0',
        plugin_name: wlName?.value || '',
        plugin_description: wlDescription?.value || '',
        author_name: wlAuthor?.value || '',
        author_url: wlAuthorUrl?.value || '',
        menu_icon: wlIcon?.value || '',
        hide_branding: wlHideBranding?.checked ? '1' : '0',
        hide_account_menu: wlHideAccountMenu?.checked ? '1' : '0',
        hide_contact_menu: wlHideContactMenu?.checked ? '1' : '0',
        hide_view_details: wlHideViewDetails?.checked ? '1' : '0',
        only_deactivate_action: wlOnlyDeactivateAction?.checked ? '1' : '0',
        visible_user_id: wlVisibleUser?.value || '0',
      });

      if (response.status === 'ok') {
        if (wlStatus) {
          wlStatus.innerHTML = '<span class="trinity-text-success">Settings saved! Reload the page to see changes.</span>';
          wlStatus.style.color = '#00a32a';
          setTimeout(() => { wlStatus.textContent = ''; }, 5000);
        }
      } else {
        if (wlStatus) {
          wlStatus.innerHTML = '<span class="trinity-text-danger">' + (response.message || 'Failed to save') + '</span>';
          wlStatus.style.color = '#d63638';
        }
      }
    } catch (err) {
      if (wlStatus) {
        wlStatus.innerHTML = '<span class="trinity-text-danger">Error saving settings</span>';
        wlStatus.style.color = '#d63638';
      }
    }

    wlSaveBtn.disabled = false;
    wlSaveBtn.innerHTML = '<img src="' + (TrinityBackup?.assetUrl || '') + 'img/icon-save.svg" class="trinity-icon" alt=""> Save Settings';
  };

  if (wlSaveBtn) {
    wlSaveBtn.addEventListener('click', saveWhiteLabelSettings);
  }
  
  // Toggle time row visibility based on frequency
  if (scheduleFrequency) {
    scheduleFrequency.addEventListener('change', updateTimeRowVisibility);
    updateTimeRowVisibility(); // Initial state
  }

  // === MAIN EXPORT/IMPORT LOOP ===
  const runLoop = async (jobId, action) => {
    let lastStage = '';
    let lastStats = {};
    let lastUrlChanged = false;
    let lastNewSiteUrl = '';
    
    while (running) {
      let response;
      
      try {
        response = await post(action, { job_id: jobId });
      } catch (networkError) {
        // Network error during import - likely due to URL change after DB import
        // If we were past the database import stage, consider it successful
        if (action === 'trinity_backup_import_run' && 
            (lastStage === 'import_files' || lastStage === 'apply_manifest' || lastStage === 'url_replace' || lastStage === 'import_db')) {
          setProgress(100);
          setStatus('Import Complete', 'success');
          setLog('Site restored successfully! The site URL may have changed.');
          showReloginButton(lastNewSiteUrl || null);
          hideSpinner();
          running = false;
          setUiRunning(false);
          return;
        }
        handleError('Network error. Please refresh the page and check if the import completed.');
        return;
      }

      if (response && response.success === false) {
        // WordPress error (e.g., invalid nonce after URL change)
        // If we were past the database import stage, consider it successful
        if (action === 'trinity_backup_import_run' && 
            (lastStage === 'import_files' || lastStage === 'apply_manifest' || lastStage === 'url_replace' || lastStage === 'import_db')) {
          setProgress(100);
          setStatus('Import Complete', 'success');
          setLog('Site restored successfully! You may need to log in again.');
          showReloginButton(lastNewSiteUrl || null);
          hideSpinner();
          running = false;
          setUiRunning(false);
          return;
        }
        handleError(response.data?.message);
        return;
      }

      if (!response || response.status === 'error') {
        // If we were past the database import stage, consider it successful
        if (action === 'trinity_backup_import_run' && 
            (lastStage === 'import_files' || lastStage === 'apply_manifest' || lastStage === 'url_replace' || lastStage === 'import_db' || lastStage === 'done')) {
          setProgress(100);
          setStatus('Import Complete', 'success');
          setLog('Site restored successfully! You may need to log in again.');
          showReloginButton(lastNewSiteUrl || null);
          hideSpinner();
          running = false;
          setUiRunning(false);
          return;
        }
        handleError(response?.message || 'An error occurred during import.');
        return;
      }

      // Track current stage for error recovery
      lastStage = response.stage || lastStage;
      lastStats = response.stats || lastStats;
      if (response.url_changed) {
        lastUrlChanged = true;
        lastNewSiteUrl = response.new_site_url || '';
      }

      // Convert stage to human-readable status
      const stageLabels = {
        'db': 'Exporting Database',
        'files': 'Exporting Files',
        'extract': 'Extracting Archive',
        'import_db': 'Importing Database',
        'import_files': 'Restoring Files',
        'apply_manifest': 'Applying Settings',
        'url_replace': 'Replacing URLs'
      };
      const stageLabel = stageLabels[response.stage] || response.stage || 'Working';
      
      if (typeof response.progress === 'number') {
        setProgress(response.progress);
      }

      // Build informative log message
      const stats = response.stats || {};
      const logParts = [];
      
      // Stage-specific detailed info
      if (response.stage === 'db' || response.stage === 'import_db') {
        if (stats.current_table) {
          logParts.push(`Table: ${stats.current_table}`);
        }
        if (stats.rows > 0) {
          logParts.push(`${stats.rows.toLocaleString()} rows`);
        }
        if (stats.statements > 0) {
          logParts.push(`${stats.statements.toLocaleString()} statements`);
        }
        // If nothing to show yet
        if (logParts.length === 0) {
          logParts.push('Working...');
        }
      } else if (response.stage === 'files' || response.stage === 'import_files') {
        // Show current file first if available
        if (stats.current_file) {
          const filename = stats.current_file.split('/').pop();
          logParts.push(`Writing: ${filename}`);
        }
        // Show files count only if > 0
        if (stats.files > 0) {
          logParts.push(`${stats.files.toLocaleString()} files processed`);
        } else if (!stats.current_file) {
          // No files yet and no current file - scanning
          logParts.push('Working...');
        }
      } else if (response.stage === 'extract') {
        if (stats.files > 0) {
          logParts.push(`${stats.files.toLocaleString()} files extracted`);
        } else {
          logParts.push('Reading archive...');
        }
      } else if (response.stage === 'url_replace') {
        if (stats.urls_replaced > 0) {
          logParts.push(`${stats.urls_replaced.toLocaleString()} URLs replaced`);
        } else {
          logParts.push('Working...');
        }
      }
      
      // Set status with stage label only (details are in log below)
      setStatus(stageLabel);
      
      // Set detailed log
      if (logParts.length > 0) {
        setLog(logParts.join(' • '));
      } else if (response.message) {
        setLog(response.message);
      }

      if (response.status === 'done') {
        setProgress(100);
        
        if (response.download_url) {
          downloadEl.href = response.download_url;
          downloadEl.style.display = 'inline-flex';
          setStatus('Export Complete', 'success');
          
          // Auto-download if option is checked
          if (optAutoDownload?.checked) {
            setLog('Backup created successfully. Download starting...');
            // Trigger download
            const link = document.createElement('a');
            link.href = response.download_url;
            link.download = '';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
          } else {
            setLog('Backup created successfully. Click "Download Backup" to save the file.');
          }
          
          loadBackupsList(); // Refresh list
        } else {
          setStatus('Import Complete', 'success');
          const stats = response.stats || {};
          const summary = [];
          if (stats.statements) summary.push(`${stats.statements} SQL statements`);
          if (stats.files) summary.push(`${stats.files} files`);
          if (stats.urls_replaced) summary.push(`${stats.urls_replaced} URLs replaced`);
          
          // Check if URL changed (migration to different domain)
          if (response.url_changed && response.new_site_url) {
            setLog(
              'Site restored successfully! ' + 
              (summary.length ? `(${summary.join(', ')}) ` : '') +
              'URLs have been updated to the new domain.'
            );
            showReloginButton(response.new_site_url);
          } else {
            setLog(
              'Site restored successfully! ' + 
              (summary.length ? `(${summary.join(', ')}) ` : '') +
              'You may need to log in again.'
            );
            showReloginButton();
          }
        }
        
        hideSpinner();
        running = false;
        setUiRunning(false);
        return;
      }

      await sleep(400);
    }
  };

  // === ENCRYPT CHECKBOX TOGGLE ===
  if (optEncrypt && passwordFields) {
    optEncrypt.addEventListener('change', () => {
      passwordFields.style.display = optEncrypt.checked ? 'block' : 'none';
      if (!optEncrypt.checked) {
        optPassword.value = '';
        optPasswordConfirm.value = '';
      }
    });
  }

  // === PASSWORD VISIBILITY TOGGLE ===
  document.querySelectorAll('.trinity-toggle-password').forEach(toggle => {
    toggle.addEventListener('click', () => {
      const targetId = toggle.dataset.target;
      const input = document.getElementById(targetId);
      if (input) {
        const img = toggle.querySelector('img');
        if (input.type === 'text') {
          input.type = 'password';
          if (img) img.src = img.src.replace('icon-visibility-off.svg', 'icon-visibility.svg');
          toggle.title = 'Show password';
        } else {
          input.type = 'text';
          if (img) img.src = img.src.replace('icon-visibility.svg', 'icon-visibility-off.svg');
          toggle.title = 'Hide password';
        }
      }
    });
  });

  // === EXPORT BUTTON ===
  startButton.addEventListener('click', async () => {
    if (running) return;

    if (!(await ensureNoOperationLock())) {
      return;
    }

    // Validate export options
    const validationError = validateExportOptions();
    if (validationError) {
      currentMode = 'export';
      showFeedback('export');
      handleError(validationError);
      return;
    }

    running = true;
    currentMode = 'export';
    showFeedback('export');
    showSpinner();
    setUiRunning(true);
    downloadEl.style.display = 'none';
    setProgress(0);
    setStatus('Starting');
    setLog('Initializing export...');

    const options = getExportOptions();
    const response = await post('trinity_backup_start', options);

    if (response && response.success === false) {
      handleError(response.data?.message);
      return;
    }

    if (!response || response.status === 'error') {
      handleError(response?.message);
      return;
    }

    if (!response.job_id) {
      handleError('Missing export job id.');
      return;
    }

    setStatus('Database');
    if (typeof response.progress === 'number') {
      setProgress(response.progress);
    }

    await runLoop(response.job_id, 'trinity_backup_run');
  });

  // === IMPORT BUTTON ===
  if (importButton && importInput) {
    importButton.addEventListener('click', async () => {
      if (running) return;

      if (!(await ensureNoOperationLock())) {
        return;
      }

      if (!importInput.files || !importInput.files[0]) {
        currentMode = 'import';
        showFeedback('import');
        handleError('Select a .trinity archive to import.');
        return;
      }

      // Show confirmation modal
      const confirmed = await showImportConfirmation();
      if (!confirmed) return;

      running = true;
      currentMode = 'import';
      showFeedback('import');
      showSpinner();
      setUiRunning(true);
      downloadEl.style.display = 'none';
      setProgress(0);
      setStatus('Uploading');
      setLog('Uploading archive...');

      let uploadResponse;
      try {
        uploadResponse = await uploadFileChunked(importInput.files[0], (progress) => {
          setProgress(progress * 0.2); // Upload is 20% of total progress
          setLog(`Uploading... ${progress}%`);
        });
      } catch (err) {
        handleError(err.message);
        return;
      }

      if (!uploadResponse || uploadResponse.status !== 'ok') {
        handleError(uploadResponse?.message || 'Upload failed');
        return;
      }

      // Check if archive is encrypted
      setStatus('Checking archive');
      setLog('Verifying archive...');
      setProgress(22);

      let archivePassword = '';
      const checkResponse = await post('trinity_backup_check_archive', {
        archive_path: uploadResponse.path
      });

      if (checkResponse.success === false) {
        handleError(checkResponse.data?.message || 'Failed to check archive');
        return;
      }

      if (checkResponse.encrypted && checkResponse.password_required) {
        // Archive is encrypted - ask for password
        let passwordValid = false;
        let errorMessage = null;
        let attempts = 0;
        const maxAttempts = 10;

        while (!passwordValid && attempts < maxAttempts) {
          attempts++;
          const attemptsLeft = maxAttempts - attempts;
          const enteredPassword = await showPasswordPrompt(errorMessage, attemptsLeft);
          
          if (enteredPassword === null) {
            // User cancelled
            handleError('Import cancelled - password required.');
            return;
          }

          // Verify password
          setLog('Verifying password...');
          const verifyResponse = await post('trinity_backup_check_archive', {
            archive_path: uploadResponse.path,
            password: enteredPassword
          });

          if (verifyResponse.success === false) {
            handleError(verifyResponse.data?.message || 'Failed to verify password');
            return;
          }

          if (verifyResponse.password_valid) {
            archivePassword = enteredPassword;
            passwordValid = true;
          } else {
            errorMessage = `Incorrect password. ${attemptsLeft > 0 ? `${attemptsLeft} attempts remaining.` : 'Last attempt!'}`;
          }
        }

        if (!passwordValid) {
          handleError('Too many incorrect password attempts. Import cancelled.');
          return;
        }
      }

      setStatus('Starting import');
      setLog('Initializing import...');
      setProgress(25);

      const importOptions = getImportOptions();
      if (archivePassword) {
        importOptions.password = archivePassword;
      }
      
      const startResponse = await post('trinity_backup_import_start', {
        archive_path: uploadResponse.path,
        ...importOptions
      });

      if (startResponse && startResponse.success === false) {
        handleError(startResponse.data?.message);
        return;
      }

      if (!startResponse || startResponse.status === 'error') {
        handleError(startResponse?.message);
        return;
      }

      if (!startResponse.job_id) {
        handleError('Missing import job id.');
        return;
      }

      await runLoop(startResponse.job_id, 'trinity_backup_import_run');
    });
  }

  // === DROPZONE DRAG & DROP ===
  if (dropzone && importInput) {
    const dropzoneContent = dropzone.querySelector('.trinity-backup__dropzone-content');
    const dropzoneText = dropzone.querySelector('.trinity-backup__dropzone-text');
    const dropzoneIcon = dropzone.querySelector('.trinity-backup__dropzone-icon');
    
    const updateDropzoneState = () => {
      if (importInput.files && importInput.files[0]) {
        const file = importInput.files[0];
        dropzone.classList.add('has-file');
        if (dropzoneIcon) dropzoneIcon.innerHTML = '<img src="' + (TrinityBackup?.assetUrl || '') + 'img/icon-package.svg" class="trinity-icon" style="width: 48px; height: 48px;" alt="">';
        dropzoneText.innerHTML = `<strong style="font-size: 15px;">${file.name}</strong><br><span style="font-size: 13px; color: var(--trinity-text-secondary, #50575e); margin-top: 4px; display: inline-block;">${formatBytes(file.size)} • Ready to import</span>`;
        importButton.style.display = 'inline-flex';
      } else {
        dropzone.classList.remove('has-file');
        if (dropzoneIcon) dropzoneIcon.innerHTML = '<img src="' + (TrinityBackup?.assetUrl || '') + 'img/icon-folder.svg" class="trinity-icon" style="width: 48px; height: 48px;" alt="">';
        dropzoneText.textContent = 'Drag & drop .trinity file here or click to browse';
        importButton.style.display = 'none';
      }
    };

    importInput.addEventListener('change', updateDropzoneState);

    dropzone.addEventListener('dragover', (e) => {
      e.preventDefault();
      dropzone.classList.add('drag-over');
    });

    dropzone.addEventListener('dragleave', (e) => {
      e.preventDefault();
      dropzone.classList.remove('drag-over');
    });

    dropzone.addEventListener('drop', (e) => {
      e.preventDefault();
      dropzone.classList.remove('drag-over');
      
      const files = e.dataTransfer.files;
      if (files.length > 0) {
        const file = files[0];
        if (file.name.endsWith('.trinity')) {
          // Create a new FileList-like object
          const dataTransfer = new DataTransfer();
          dataTransfer.items.add(file);
          importInput.files = dataTransfer.files;
          updateDropzoneState();
        } else {
          alert('Please drop a .trinity archive file.');
        }
      }
    });
  }

  // === BACKUPS LIST HANDLERS ===
  if (refreshBackupsBtn) {
    refreshBackupsBtn.addEventListener('click', loadBackupsList);
    loadBackupsList(); // Load on page init
  }

  if (cleanupBtn) {
    cleanupBtn.addEventListener('click', cleanupTempFiles);
  }

  // Start operation-lock polling on page init so lock UI/toast works even
  // if the backups list is empty or schedule UI is not present for any reason.
  startOperationLockPolling();
})();
