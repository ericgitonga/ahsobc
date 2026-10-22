/**
 * Flickr-Style Controller for the AHSOBC Headless Omeka S Gallery (/gallery/).
 *
 * Powers:
 *   1. Clean Tab Switching: Photostream | Albums | People
 *   2. Instant Omni-Search & Album Filtering via /api/gallery.php
 *   3. Per-Image Theatre Lightbox (#ahsobc-lightbox) with Zoom, Face Bounding Boxes,
 *      Metadata View, and Inline Metadata Editing (POST /api/gallery.php)
 *   4. Modal Dialog for Photo Uploads & Face Identification
 */
(function () {
    'use strict';

    const photoGrid = document.getElementById('ahsobc-photo-wall');
    const tabCountEl = document.getElementById('ahsobc-tab-count');
    const searchInput = document.getElementById('filter-q');
    const lightbox = document.getElementById('ahsobc-lightbox');

    const panelPhotostream = document.getElementById('view-panel-photostream');
    const panelAlbums = document.getElementById('view-panel-albums');
    const panelPeople = document.getElementById('view-panel-people');

    if (!photoGrid) {
        return;
    }

    let currentItems = Array.isArray(window.AHSOBC_INITIAL_ITEMS) ? window.AHSOBC_INITIAL_ITEMS : [];
    let activeLightboxIndex = -1;
    let currentZoom = 1;
    let showFaceBoxes = true;
    let debounceTimer = null;

    function escapeHtml(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // 1. Tab Switching (Photostream | Albums | People)
    function activateTab(tabName) {
        document.querySelectorAll('[data-switch-tab]').forEach(function (tabLink) {
            tabLink.classList.toggle('active', tabLink.getAttribute('data-switch-tab') === tabName);
        });
        if (panelPhotostream) panelPhotostream.hidden = tabName !== 'photostream';
        if (panelAlbums) panelAlbums.hidden = tabName !== 'albums';
        if (panelPeople) panelPeople.hidden = tabName !== 'people';
    }

    document.querySelectorAll('[data-switch-tab]').forEach(function (tabLink) {
        tabLink.addEventListener('click', function (e) {
            if (e.metaKey || e.ctrlKey || e.shiftKey) return;
            e.preventDefault();
            const targetTab = tabLink.getAttribute('data-switch-tab') || 'photostream';
            activateTab(targetTab);
            const url = targetTab === 'photostream' ? '/gallery/' : '/gallery/?tab=' + encodeURIComponent(targetTab);
            window.history.replaceState({}, '', url);
        });
    });

    // 2. Render Flickr Photostream Tiles
    function renderPhotostream(items) {
        currentItems = items;
        if (tabCountEl) {
            tabCountEl.textContent = '(' + items.length + ')';
        }

        if (items.length === 0) {
            photoGrid.innerHTML =
                '<div style="grid-column:1/-1;text-align:center;padding:3rem;background:#fff;border-radius:8px;border:1px solid #dce3ea;">' +
                '<h3 style="color:#072a47;margin-bottom:0.4rem;">No matching photographs</h3>' +
                '<p style="color:#5b6876;margin-bottom:1rem;">Try a different search term or return to all photos.</p>' +
                '<a href="/gallery/" class="btn-primary">View All Photos</a>' +
                '</div>';
            return;
        }

        photoGrid.innerHTML = items
            .map(function (item, idx) {
                return (
                    '<article class="photo-card" data-index="' +
                    idx +
                    '" data-slug="' +
                    escapeHtml(item.slug) +
                    '" data-decade="' +
                    escapeHtml(item.decade) +
                    '" data-house="' +
                    escapeHtml(item.house) +
                    '" data-context="' +
                    escapeHtml(item.photo_context) +
                    '">' +
                    '<div class="photo-card-visual" role="button" tabindex="0" aria-label="Open ' +
                    escapeHtml(item.title) +
                    '" data-open-lightbox="' +
                    idx +
                    '">' +
                    '<img class="photo-card-img" src="' +
                    escapeHtml(item.preview_url) +
                    '" alt="' +
                    escapeHtml(item.title) +
                    '" loading="lazy">' +
                    '<div class="photo-card-overlay">' +
                    '<h3 class="photo-card-title">' +
                    '<a href="' +
                    escapeHtml(item.item_url) +
                    '" data-open-lightbox="' +
                    idx +
                    '">' +
                    escapeHtml(item.title) +
                    '</a>' +
                    '</h3>' +
                    '<div class="photo-card-sub">' +
                    '<span>' +
                    escapeHtml(item.temporal_raw) +
                    ' · ' +
                    escapeHtml(item.house) +
                    ' House</span>' +
                    '<span>' +
                    escapeHtml(item.photo_context) +
                    '</span>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '</article>'
                );
            })
            .join('');
    }

    // 3. Instant Omni-Search via /api/gallery.php
    function runLiveSearch(queryParams) {
        const params = new URLSearchParams(queryParams || {});
        if (searchInput && searchInput.value.trim() !== '') {
            params.set('q', searchInput.value.trim());
        }
        const qs = params.toString();
        activateTab('photostream');
        window.history.replaceState({}, '', '/gallery/' + (qs ? '?' + qs : ''));

        fetch('/api/gallery.php' + (qs ? '?' + qs : ''))
            .then(function (r) {
                return r.json();
            })
            .then(function (data) {
                if (data && Array.isArray(data.items)) {
                    renderPhotostream(data.items);
                }
            })
            .catch(function () {});
    }

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function () {
                runLiveSearch({});
            }, 180);
        });
    }

    // 4. Per-Image Theatre Lightbox & Inline Metadata Editor
    const metadataViewEl = document.getElementById('lb-metadata-view');
    const editFormEl = document.getElementById('lb-edit-form');
    const editToggleBtn = document.getElementById('lb-edit-toggle-btn');
    const cancelEditBtn = document.getElementById('lb-cancel-edit-btn');
    const saveStatusEl = document.getElementById('lb-save-status');

    function populateLightbox(item, index) {
        const imgEl = document.getElementById('lb-image');
        const stageInner = document.getElementById('lb-stage-inner');
        const boxesContainer = document.getElementById('lb-face-boxes');
        const titleEl = document.getElementById('lb-title');
        const badgesEl = document.getElementById('lb-badges');
        const descEl = document.getElementById('lb-description');
        const metaEl = document.getElementById('lb-meta-dl');
        const tagsListEl = document.getElementById('lb-face-tags');
        const iiifLinkEl = document.getElementById('lb-iiif-link');
        const counterEl = document.getElementById('lb-counter');

        if (imgEl) {
            imgEl.src = item.preview_url;
            imgEl.alt = item.title;
        }
        if (stageInner) {
            stageInner.style.transform = 'scale(1)';
        }
        if (counterEl) {
            counterEl.textContent = index + 1 + ' / ' + currentItems.length;
        }
        if (titleEl) {
            titleEl.textContent = item.title;
        }
        if (badgesEl) {
            badgesEl.innerHTML =
                '<span class="badge badge-context">' +
                escapeHtml(item.photo_context) +
                '</span>' +
                '<span class="badge">' +
                escapeHtml(item.temporal_raw) +
                '</span>' +
                '<span class="badge">' +
                escapeHtml(item.house) +
                ' House</span>';
        }
        if (descEl) {
            descEl.textContent = item.description;
        }
        if (metaEl) {
            const alumniHtml = (item.featured_alumni || [])
                .map(function (a) {
                    return (
                        '<a href="/gallery/?alumnus=' +
                        encodeURIComponent(a.slug) +
                        '#lifespans" style="color:#60a5fa;font-weight:600;text-decoration:none;">' +
                        escapeHtml(a.full_name) +
                        ' (#' +
                        escapeHtml(a.school_number) +
                        ')</a>'
                    );
                })
                .join(', ');
            metaEl.innerHTML =
                '<dt>Album</dt><dd><a href="/gallery/?context=' +
                encodeURIComponent(item.photo_context) +
                '" style="color:#60a5fa;text-decoration:none;">' +
                escapeHtml(item.photo_context) +
                '</a></dd>' +
                '<dt>Date Taken</dt><dd>' +
                escapeHtml(item.temporal_raw) +
                '</dd>' +
                '<dt>AHS House</dt><dd><a href="/gallery/?house=' +
                encodeURIComponent(item.house) +
                '" style="color:#60a5fa;text-decoration:none;">' +
                escapeHtml(item.house) +
                ' House</a></dd>' +
                '<dt>Class Cohort(s)</dt><dd>' +
                escapeHtml(item.cohorts_raw) +
                '</dd>' +
                '<dt>Location</dt><dd>' +
                escapeHtml(item.spatial) +
                '</dd>' +
                '<dt>Featured Alumni</dt><dd>' +
                (alumniHtml || 'None linked yet') +
                '</dd>';
        }
        if (boxesContainer) {
            const regions = item.face_regions || [];
            boxesContainer.innerHTML = regions
                .filter(function (r) {
                    return r.has_region;
                })
                .map(function (r) {
                    return (
                        '<div class="lb-face-box" style="left:' +
                        r.left_pct +
                        '%;top:' +
                        r.top_pct +
                        '%;width:' +
                        r.width_pct +
                        '%;height:' +
                        r.height_pct +
                        '%;" title="' +
                        escapeHtml(r.note) +
                        '">' +
                        '<span class="lb-face-box-label">' +
                        escapeHtml(r.note.split('(')[0]) +
                        '</span>' +
                        '</div>'
                    );
                })
                .join('');
            boxesContainer.style.display = showFaceBoxes ? 'block' : 'none';
        }
        if (tagsListEl) {
            const tags = item.face_tags || [];
            if (tags.length === 0) {
                tagsListEl.innerHTML = '<li style="color:#9ca3af;">No face identifications recorded yet.</li>';
            } else {
                tagsListEl.innerHTML = tags
                    .map(function (t) {
                        return '<li>' + escapeHtml(t) + '</li>';
                    })
                    .join('');
            }
        }
        if (iiifLinkEl) {
            iiifLinkEl.href = item.iiif_manifest_url;
        }

        // Pre-fill Inline Edit Form inputs
        const slugInput = document.getElementById('edit-slug');
        const titleInput = document.getElementById('edit-title');
        const descInput = document.getElementById('edit-description');
        const ctxInput = document.getElementById('edit-context');
        const houseInput = document.getElementById('edit-house');
        const tempInput = document.getElementById('edit-temporal');
        const cohortsInput = document.getElementById('edit-cohorts');
        const spatialInput = document.getElementById('edit-spatial');

        if (slugInput) slugInput.value = item.slug;
        if (titleInput) titleInput.value = item.title;
        if (descInput) descInput.value = item.description;
        if (ctxInput) ctxInput.value = item.photo_context;
        if (houseInput) houseInput.value = item.house;
        if (tempInput) tempInput.value = item.temporal_raw;
        if (cohortsInput) cohortsInput.value = item.cohorts_raw;
        if (spatialInput) spatialInput.value = item.spatial;
    }

    function setEditMode(editing) {
        if (metadataViewEl) metadataViewEl.style.display = editing ? 'none' : 'flex';
        if (editFormEl) editFormEl.hidden = !editing;
        if (editToggleBtn) editToggleBtn.textContent = editing ? 'Viewing Edit Form' : '✎ Edit Metadata';
    }

    function openLightbox(index) {
        if (!lightbox || index < 0 || index >= currentItems.length) return;
        activeLightboxIndex = index;
        currentZoom = 1;
        if (saveStatusEl) saveStatusEl.style.display = 'none';
        setEditMode(false);
        populateLightbox(currentItems[index], index);
        lightbox.hidden = false;
        lightbox.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
        if (!lightbox) return;
        lightbox.hidden = true;
        lightbox.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        activeLightboxIndex = -1;
    }

    photoGrid.addEventListener('click', function (e) {
        const trigger = e.target.closest('[data-open-lightbox]');
        if (!trigger || e.metaKey || e.ctrlKey || e.shiftKey || !lightbox) return;
        e.preventDefault();
        const idx = parseInt(trigger.getAttribute('data-open-lightbox') || '0', 10);
        openLightbox(idx);
    });

    if (editToggleBtn) {
        editToggleBtn.addEventListener('click', function () {
            const isCurrentlyHidden = editFormEl ? editFormEl.hidden : true;
            setEditMode(isCurrentlyHidden);
        });
    }
    if (cancelEditBtn) {
        cancelEditBtn.addEventListener('click', function () {
            setEditMode(false);
        });
    }

    // Handle Inline Metadata Save via AJAX POST to /api/gallery.php
    if (editFormEl) {
        editFormEl.addEventListener('submit', function (e) {
            e.preventDefault();
            const payload = {
                action: 'update_metadata',
                slug: document.getElementById('edit-slug').value,
                title: document.getElementById('edit-title').value,
                description: document.getElementById('edit-description').value,
                photo_context: document.getElementById('edit-context').value,
                house: document.getElementById('edit-house').value,
                temporal_raw: document.getElementById('edit-temporal').value,
                cohorts_raw: document.getElementById('edit-cohorts').value,
                spatial: document.getElementById('edit-spatial').value,
            };

            fetch('/api/gallery.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            })
                .then(function (r) {
                    return r.json();
                })
                .then(function (res) {
                    if (res && res.status === 'ok' && res.item) {
                        currentItems[activeLightboxIndex] = res.item;
                        renderPhotostream(currentItems);
                        populateLightbox(res.item, activeLightboxIndex);
                        setEditMode(false);
                        if (saveStatusEl) {
                            saveStatusEl.textContent = '✓ Metadata saved to Omeka S.';
                            saveStatusEl.style.display = 'block';
                        }
                    } else if (res && Array.isArray(res.errors)) {
                        alert(res.errors.join('\n'));
                    }
                })
                .catch(function () {
                    editFormEl.submit();
                });
        });
    }

    // Lightbox Topbar Controls
    if (lightbox) {
        const closeBtn = document.getElementById('lb-close-btn');
        const prevBtn = document.getElementById('lb-prev-btn');
        const nextBtn = document.getElementById('lb-next-btn');
        const zoomInBtn = document.getElementById('lb-zoom-in');
        const toggleFacesBtn = document.getElementById('lb-toggle-faces');
        const tagClassmateBtn = document.getElementById('lb-tag-classmate-btn');
        const stageInner = document.getElementById('lb-stage-inner');
        const boxesContainer = document.getElementById('lb-face-boxes');

        if (closeBtn) closeBtn.addEventListener('click', closeLightbox);
        if (prevBtn) {
            prevBtn.addEventListener('click', function () {
                if (currentItems.length === 0) return;
                openLightbox((activeLightboxIndex - 1 + currentItems.length) % currentItems.length);
            });
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', function () {
                if (currentItems.length === 0) return;
                openLightbox((activeLightboxIndex + 1) % currentItems.length);
            });
        }
        if (zoomInBtn && stageInner) {
            zoomInBtn.addEventListener('click', function () {
                currentZoom = currentZoom >= 2.5 ? 1 : Number((currentZoom + 0.75).toFixed(2));
                stageInner.style.transform = 'scale(' + currentZoom + ')';
                zoomInBtn.textContent = currentZoom > 1 ? 'Zoom ' + currentZoom + 'x' : 'Zoom +';
            });
        }
        if (toggleFacesBtn && boxesContainer) {
            toggleFacesBtn.addEventListener('click', function () {
                showFaceBoxes = !showFaceBoxes;
                boxesContainer.style.display = showFaceBoxes ? 'block' : 'none';
                toggleFacesBtn.textContent = showFaceBoxes ? 'Hide Face Tags' : 'Show Face Tags';
            });
        }
        if (tagClassmateBtn) {
            tagClassmateBtn.addEventListener('click', function () {
                const item = currentItems[activeLightboxIndex];
                closeLightbox();
                openUploadModal('face', item ? item.slug : null);
            });
        }

        document.addEventListener('keydown', function (e) {
            if (lightbox.hidden) return;
            if (e.target && ['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) return;
            if (e.key === 'Escape') closeLightbox();
            if (e.key === 'ArrowRight' && nextBtn) nextBtn.click();
            if (e.key === 'ArrowLeft' && prevBtn) prevBtn.click();
        });
    }

    // 5. Upload / Contribute Modal Controller
    const uploadModal = document.getElementById('upload-modal');
    const openUploadBtn = document.getElementById('open-upload-modal-btn');
    const closeUploadBtn = document.getElementById('close-upload-modal-btn');
    const modalTabPhoto = document.getElementById('modal-tab-photo');
    const modalTabFace = document.getElementById('modal-tab-face');
    const modalFormPhoto = document.getElementById('modal-form-photo');
    const modalFormFace = document.getElementById('modal-form-face');

    function openUploadModal(mode, targetSlug) {
        if (!uploadModal) return;
        const isFace = mode === 'face';
        if (modalFormPhoto) modalFormPhoto.hidden = isFace;
        if (modalFormFace) modalFormFace.hidden = !isFace;
        if (modalTabPhoto) modalTabPhoto.classList.toggle('active', !isFace);
        if (modalTabFace) modalTabFace.classList.toggle('active', isFace);
        if (targetSlug) {
            const selectEl = document.getElementById('face-target');
            if (selectEl) selectEl.value = targetSlug;
        }
        uploadModal.hidden = false;
    }

    if (openUploadBtn) {
        openUploadBtn.addEventListener('click', function () {
            openUploadModal('photo', null);
        });
    }
    if (closeUploadBtn && uploadModal) {
        closeUploadBtn.addEventListener('click', function () {
            uploadModal.hidden = true;
        });
    }
    if (modalTabPhoto) {
        modalTabPhoto.addEventListener('click', function () {
            openUploadModal('photo', null);
        });
    }
    if (modalTabFace) {
        modalTabFace.addEventListener('click', function () {
            openUploadModal('face', null);
        });
    }
})();
