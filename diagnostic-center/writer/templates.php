<?php
$page_title = "Template Library";
$required_role = "writer";
require_once '../includes/auth_check.php';
require_once '../includes/header.php';
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" integrity="sha512-" crossorigin="anonymous" referrerpolicy="no-referrer" />
<div class="main-content page-container template-hub">
    <div class="dashboard-header">
        <div>
            <h1>Template Library</h1>
            <p class="description">Pick a main test to manage its sub-test report templates. Upload new files when needed.</p>
        </div>
        <div class="page-actions">
            <a class="btn-secondary" href="dashboard.php">Back to Dashboard</a>
        </div>
    </div>

    <section id="templateExplorer">
        <header>
            <h2>Choose a Main Test</h2>
        </header>
        <div id="mainTestGrid" class="template-grid" aria-live="polite"></div>
        <div id="mainTestEmpty" class="templates-empty" hidden>
            No tests found. Please coordinate with the manager to define test catalog entries first.
        </div>
    </section>

    <section id="subtestPanel" class="subtest-panel" hidden>
        <header>
            <div class="subtest-panel__header">
                <div>
                    <h2 id="subtestTitle">Main Test</h2>
                    <p class="subtest-panel__subtitle" id="subtestDescription">
                        View, preview, or replace sub-test templates.
                    </p>
                </div>
                <button class="action-button" id="refreshSubtests">
                    <i class="fa-solid fa-rotate"></i> Refresh
                </button>
            </div>
        </header>
        <div class="report-table-wrapper">
            <table class="subtest-table">
                <thead>
                    <tr>
                        <th>Subtest Name</th>
                        <th>Template File</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="subtestTableBody"></tbody>
            </table>
            <div id="subtestEmpty" class="empty-state" hidden>
                No subtests found for this main test yet.
            </div>
        </div>
    </section>
</div>

<div class="modal" id="templatePreviewModal" aria-hidden="true">
    <div class="modal-dialog">
        <button class="modal-close" data-action="close-preview" aria-label="Close preview">&times;</button>
        <h3 id="previewTitle">Template Preview</h3>
        <div id="previewFrame" class="preview-frame" role="document"></div>
    </div>
</div>

<div class="modal" id="templateUploadModal" aria-hidden="true">
    <div class="modal-dialog">
        <button class="modal-close" data-action="close-upload" aria-label="Close uploader">&times;</button>
        <h3 id="uploadTitle">Upload Template</h3>
        <form id="templateUploadForm" enctype="multipart/form-data">
            <input type="hidden" name="main_test" id="uploadMainTest">
            <input type="hidden" name="sub_test_id" id="uploadSubTestId">
            <div class="form-group">
                <label for="templateFileInput">Choose template file</label>
                <input type="file" id="templateFileInput" name="template_file" accept=".docx" required>
                <small>Upload DOCX templates only. Replace existing files by re-uploading.</small>
            </div>
            <div class="template-upload__actions">
                <button type="button" class="btn-secondary" data-action="close-upload">Cancel</button>
                <button type="submit" class="btn-primary" id="uploadSubmitBtn">Upload</button>
            </div>
            <p class="upload-feedback" id="uploadFeedback"></p>
        </form>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.7.0/mammoth.browser.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
(function() {
    const mainGrid = document.getElementById('mainTestGrid');
    const mainEmpty = document.getElementById('mainTestEmpty');
    const subtestPanel = document.getElementById('subtestPanel');
    const subtestTitle = document.getElementById('subtestTitle');
    const subtestTableBody = document.getElementById('subtestTableBody');
    const subtestEmpty = document.getElementById('subtestEmpty');
    const refreshBtn = document.getElementById('refreshSubtests');

    const previewModal = document.getElementById('templatePreviewModal');
    const previewCanvas = document.getElementById('previewFrame');
    const previewTitle = document.getElementById('previewTitle');

    const uploadModal = document.getElementById('templateUploadModal');
    const uploadForm = document.getElementById('templateUploadForm');
    const uploadFeedback = document.getElementById('uploadFeedback');
    const uploadMainTest = document.getElementById('uploadMainTest');
    const uploadSubTestId = document.getElementById('uploadSubTestId');
    const uploadTitle = document.getElementById('uploadTitle');
    const templateFileInput = document.getElementById('templateFileInput');

    let activeMainTest = null;

    const modals = [previewModal, uploadModal];

    const closeModal = (modalEl) => {
        modalEl.classList.remove('is-visible');
        modalEl.setAttribute('aria-hidden', 'true');
        if (modalEl === previewModal) {
            previewCanvas.innerHTML = '';
        }
        if (modalEl === uploadModal) {
            uploadForm.reset();
            uploadFeedback.textContent = '';
        }
    };

    const openModal = (modalEl) => {
        modalEl.classList.add('is-visible');
        modalEl.setAttribute('aria-hidden', 'false');
    };

    modals.forEach((modal) => {
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeModal(modal);
            }
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            modals.forEach((modal) => {
                if (modal.classList.contains('is-visible')) {
                    closeModal(modal);
                }
            });
        }
    });

    const fetchJSON = async (action, payload = {}) => {
        const response = await fetch(`templates_ajax.php?action=${encodeURIComponent(action)}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        });
        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Unable to complete request.');
        }
        return data;
    };

    const loadMainTests = async () => {
        mainGrid.innerHTML = '';
        mainEmpty.hidden = true;
        try {
            const data = await fetchJSON('list_main_tests');
            if (!data.tests || data.tests.length === 0) {
                mainEmpty.hidden = false;
                return;
            }
            data.tests.forEach((test) => {
                const card = document.createElement('button');
                card.type = 'button';
                card.className = 'template-card';
                card.dataset.mainTest = test;
                card.textContent = test;
                card.addEventListener('click', () => selectMainTest(test, card));
                mainGrid.appendChild(card);
            });
        } catch (error) {
            mainEmpty.hidden = false;
            mainEmpty.textContent = error.message;
        }
    };

    const selectMainTest = (testName, card) => {
        activeMainTest = testName;
        document.querySelectorAll('.template-card').forEach((btn) => btn.classList.remove('is-active'));
        card.classList.add('is-active');
        subtestTitle.textContent = testName;
        subtestPanel.hidden = false;
        loadSubtests();
    };

    const createActionCell = (row) => {
        const td = document.createElement('td');
        td.className = 'template-actions';

        const viewBtn = document.createElement('button');
        viewBtn.type = 'button';
        viewBtn.className = 'action-button';
        viewBtn.dataset.action = 'preview';
        viewBtn.dataset.sourceUrl = row.preview_url || '';
        viewBtn.dataset.subtest = row.sub_test_name || '';
        viewBtn.textContent = 'View';
        if (!row.preview_url) {
            viewBtn.disabled = true;
        }
        td.appendChild(viewBtn);

        const uploadBtn = document.createElement('button');
        uploadBtn.type = 'button';
        uploadBtn.className = 'action-button';
        uploadBtn.dataset.action = 'open-upload';
        uploadBtn.dataset.subtestId = row.sub_test_id;
        uploadBtn.dataset.subtest = row.sub_test_name || '';
        uploadBtn.textContent = row.template_exists ? 'Replace Template' : 'Add Template';
        td.appendChild(uploadBtn);

        if (row.template_exists && row.download_url) {
            const downloadLink = document.createElement('a');
            downloadLink.className = 'action-link';
            downloadLink.href = row.download_url;
            downloadLink.target = '_blank';
            downloadLink.rel = 'noopener';
            downloadLink.textContent = 'Download';
            td.appendChild(downloadLink);
        } else {
            const helper = document.createElement('small');
            helper.textContent = 'Upload to enable preview & download.';
            td.appendChild(helper);
        }

        return td;
    };

    const loadSubtests = async () => {
        if (!activeMainTest) {
            return;
        }
        subtestTableBody.innerHTML = '';
        subtestEmpty.hidden = true;
        try {
            const data = await fetchJSON('list_subtests', { main_test: activeMainTest });
            if (!data.subtests || data.subtests.length === 0) {
                subtestEmpty.hidden = false;
                return;
            }
            data.subtests.forEach((row) => {
                const tr = document.createElement('tr');

                const nameTd = document.createElement('td');
                nameTd.textContent = row.sub_test_name || '—';
                tr.appendChild(nameTd);

                const fileTd = document.createElement('td');
                fileTd.textContent = row.template_exists ? (row.template_label || 'Template Attached') : 'No template uploaded';
                tr.appendChild(fileTd);

                tr.appendChild(createActionCell(row));

                subtestTableBody.appendChild(tr);
            });
        } catch (error) {
            subtestEmpty.hidden = false;
            subtestEmpty.textContent = error.message;
        }
    };

    const loadDocPreview = async (sourceUrl) => {
        if (!sourceUrl) {
            previewCanvas.innerHTML = '<p>No template uploaded for this sub-test yet.</p>';
            return;
        }
        if (typeof window.mammoth === 'undefined') {
            previewCanvas.innerHTML = '<p>Preview engine unavailable. Please download the file instead.</p>';
            return;
        }
        previewCanvas.innerHTML = '<p>Loading preview...</p>';
        try {
            const response = await fetch(sourceUrl);
            if (!response.ok) {
                throw new Error('Unable to fetch template file.');
            }
            const buffer = await response.arrayBuffer();
            const result = await window.mammoth.convertToHtml({ arrayBuffer: buffer });
            previewCanvas.innerHTML = result.value || '<p>The template is empty.</p>';
        } catch (error) {
            previewCanvas.innerHTML = `<p style="color:#b91c1c;">${error.message}</p><p><small>Please use the download option instead.</small></p>`;
        }
    };

    document.addEventListener('click', (event) => {
        const closePreview = event.target.closest('[data-action="close-preview"]');
        if (closePreview) {
            closeModal(previewModal);
            return;
        }
        const closeUpload = event.target.closest('[data-action="close-upload"]');
        if (closeUpload) {
            closeModal(uploadModal);
            return;
        }

        const previewBtn = event.target.closest('[data-action="preview"]');
        if (previewBtn) {
            const src = previewBtn.dataset.sourceUrl || '';
            const subtestLabel = previewBtn.dataset.subtest || '';
            previewTitle.textContent = subtestLabel ? `Template Preview · ${subtestLabel}` : 'Template Preview';
            openModal(previewModal);
            loadDocPreview(src);
            return;
        }

        const uploadBtn = event.target.closest('[data-action="open-upload"]');
        if (uploadBtn) {
            uploadSubTestId.value = uploadBtn.dataset.subtestId || '';
            uploadMainTest.value = activeMainTest || '';
            uploadFeedback.textContent = '';
            templateFileInput.value = '';
            const subtestLabel = uploadBtn.dataset.subtest || '';
            uploadTitle.textContent = subtestLabel ? `Upload Template · ${subtestLabel}` : 'Upload Template';
            openModal(uploadModal);
        }
    });

    uploadForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!uploadSubTestId.value) {
            uploadFeedback.textContent = 'Missing sub-test reference. Try again.';
            return;
        }
        const formData = new FormData(uploadForm);
        try {
            const response = await fetch('templates_ajax.php?action=upload_template', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            const payload = await response.json();
            if (!response.ok || !payload.success) {
                throw new Error(payload.message || 'Failed to upload template.');
            }
            uploadFeedback.textContent = 'Template uploaded successfully!';
            setTimeout(() => {
                closeModal(uploadModal);
                loadSubtests();
            }, 750);
        } catch (error) {
            uploadFeedback.textContent = error.message;
        }
    });

    refreshBtn.addEventListener('click', () => loadSubtests());

    loadMainTests();
})();
</script>

<?php require_once '../includes/footer.php'; ?>
