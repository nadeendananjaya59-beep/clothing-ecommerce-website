'use strict';

document.addEventListener('DOMContentLoaded', function() {
    var deleteModal = document.getElementById('deleteProductModal');
    if (deleteModal) {
        deleteModal.addEventListener('show.bs.modal', function(event) {
            var trigger = event.relatedTarget;
            if (!trigger) {
                return;
            }

            var productId = trigger.getAttribute('data-product-id') || '';
            var productName = trigger.getAttribute('data-product-name') || 'this product';
            var idField = deleteModal.querySelector('[data-delete-product-id]');
            var nameLabel = deleteModal.querySelector('[data-delete-product-name]');

            if (idField) {
                idField.value = productId;
            }

            if (nameLabel) {
                nameLabel.textContent = productName;
            }
        });
    }

    var autoSubmitFields = document.querySelectorAll('[data-submit-on-change]');
    autoSubmitFields.forEach(function(field) {
        field.addEventListener('change', function() {
            var form = this.closest('form');
            if (form) {
                form.submit();
            }
        });
    });

    var stockInput = document.getElementById('stockQtyInput');
    var reorderInput = document.getElementById('reorderLevelInput');
    var statusSelect = document.getElementById('statusSelect');
    var stockHint = document.getElementById('stockStatusHint');
    var costInput = document.getElementById('costPriceInput');
    var sellingInput = document.getElementById('sellingPriceInput');
    var marginValue = document.getElementById('marginPreviewValue');
    var galleryUploadInput = document.getElementById('galleryUploadInput');
    var galleryUploadPreview = document.getElementById('galleryUploadPreview');

    function formatFileSize(size) {
        if (!size || size <= 0) {
            return '0 KB';
        }

        if (size >= 1024 * 1024) {
            return (size / (1024 * 1024)).toFixed(2) + ' MB';
        }

        return Math.max(1, Math.round(size / 1024)) + ' KB';
    }

    function escapeHtml(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function buildPreviewCard(file, dataUrl) {
        return [
            '<div class="upload-preview-card">',
            dataUrl ? '<img src="' + dataUrl + '" alt="' + escapeHtml(file.name) + '">' : '',
            '<div class="upload-preview-meta">',
            '<span class="upload-preview-name">' + escapeHtml(file.name) + '</span>',
            '<span class="upload-preview-size">' + formatFileSize(file.size) + '</span>',
            '</div>',
            '</div>'
        ].join('');
    }

    function showEmptyPreview(container, message) {
        if (!container) {
            return;
        }

        container.innerHTML = '<div class="upload-preview-empty">' + escapeHtml(message) + '</div>';
    }

    function renderFilePreviews(files, container) {
        if (!container) {
            return;
        }

        if (!files || !files.length) {
            showEmptyPreview(container, 'Selected files will preview here before upload.');
            return;
        }

        container.innerHTML = '';

        Array.prototype.forEach.call(files, function(file) {
            if (!file || !file.type || file.type.indexOf('image/') !== 0) {
                container.insertAdjacentHTML('beforeend', buildPreviewCard(file, ''));
                return;
            }

            var reader = new FileReader();
            reader.onload = function(event) {
                container.insertAdjacentHTML('beforeend', buildPreviewCard(file, event.target.result));
            };
            reader.readAsDataURL(file);
        });
    }

    function syncStatusHint() {
        if (!stockInput || !reorderInput || !statusSelect || !stockHint) {
            return;
        }

        var stock = parseInt(stockInput.value || '0', 10);
        var reorder = parseInt(reorderInput.value || '0', 10);

        if (isNaN(stock) || stock <= 0) {
            statusSelect.value = 'Out of Stock';
            stockHint.textContent = 'This product will be saved as Out of Stock.';
            return;
        }

        if (statusSelect.value === 'Out of Stock') {
            stockHint.textContent = 'Stock exists, but this product is manually marked as Out of Stock.';
        } else if (!isNaN(reorder) && reorder > 0 && stock <= reorder) {
            stockHint.textContent = 'This product is still available but currently below its reorder level.';
        } else {
            stockHint.textContent = 'This product will be saved as Available.';
        }
    }

    function syncMarginPreview() {
        if (!costInput || !sellingInput || !marginValue) {
            return;
        }

        var cost = parseFloat(costInput.value || '0');
        var selling = parseFloat(sellingInput.value || '0');

        if (isNaN(cost) || isNaN(selling) || selling <= 0) {
            marginValue.textContent = 'LKR 0.00';
            return;
        }

        var margin = selling - cost;
        marginValue.textContent = 'LKR ' + margin.toFixed(2);
    }

    [stockInput, reorderInput].forEach(function(field) {
        if (field) {
            field.addEventListener('input', syncStatusHint);
        }
    });

    if (statusSelect) {
        statusSelect.addEventListener('change', syncStatusHint);
    }

    [costInput, sellingInput].forEach(function(field) {
        if (field) {
            field.addEventListener('input', syncMarginPreview);
        }
    });

    if (galleryUploadInput && galleryUploadPreview) {
        galleryUploadInput.addEventListener('change', function() {
            renderFilePreviews(this.files, galleryUploadPreview);
        });
    }

    document.querySelectorAll('.js-image-replace-input').forEach(function(input) {
        input.addEventListener('change', function() {
            var targetId = this.getAttribute('data-preview-target');
            if (!targetId) {
                return;
            }

            var target = document.getElementById(targetId);
            renderFilePreviews(this.files, target);
        });
    });

    syncStatusHint();
    syncMarginPreview();

    if (galleryUploadPreview && !galleryUploadPreview.children.length) {
        showEmptyPreview(galleryUploadPreview, 'Selected files will preview here before upload.');
    }

    function getSvgFromTarget(selector) {
        if (!selector) {
            return null;
        }

        var source = document.querySelector(selector);
        if (!source) {
            return null;
        }

        if (source.tagName && source.tagName.toLowerCase() === 'svg') {
            return source;
        }

        return source.querySelector('svg');
    }

    function downloadSvg(selector, filename) {
        var svg = getSvgFromTarget(selector);
        if (!svg) {
            return;
        }

        var serializer = new XMLSerializer();
        var svgMarkup = serializer.serializeToString(svg);
        var blob = new Blob([svgMarkup], { type: 'image/svg+xml;charset=utf-8' });
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');

        link.href = url;
        link.download = filename || 'label.svg';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        setTimeout(function() {
            URL.revokeObjectURL(url);
        }, 1000);
    }

    function printLabelCard(selector, title) {
        var source = document.querySelector(selector);
        if (!source) {
            return;
        }

        var printWindow = window.open('', '_blank', 'width=900,height=700');
        if (!printWindow) {
            return;
        }

        var printTitle = title || document.title || 'Label Print';
        printWindow.document.open();
        printWindow.document.write([
            '<!DOCTYPE html>',
            '<html lang="en"><head><meta charset="UTF-8">',
            '<meta name="viewport" content="width=device-width, initial-scale=1.0">',
            '<title>' + escapeHtml(printTitle) + '</title>',
            '<style>',
            'body{margin:0;padding:32px;font-family:Arial,sans-serif;background:#ffffff;color:#111827;}',
            '.print-shell{max-width:760px;margin:0 auto;}',
            '.print-card{border:1px solid #d1d5db;border-radius:18px;padding:28px;}',
            '.print-card svg{max-width:100%;height:auto;display:block;margin:0 auto;}',
            '.print-card .module-pill,.print-card .status-pill{display:inline-flex;align-items:center;padding:6px 12px;border-radius:999px;border:1px solid #d1d5db;font-size:12px;font-weight:700;color:#374151;background:#f8fafc;}',
            '.print-card .code-preview-title{font-size:28px;font-weight:700;margin:14px 0 8px;}',
            '.print-card .product-subline{font-size:14px;color:#4b5563;margin-top:10px;}',
            '.print-card .barcode-value{margin-top:16px;font-size:18px;font-weight:700;letter-spacing:1px;word-break:break-word;}',
            '.print-card .label-preview-meta{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px;}',
            '.print-card .qr-preview-holder{display:flex;justify-content:center;align-items:center;margin:14px 0;}',
            '@media print{body{padding:0;background:#fff;}.print-shell{max-width:none;}.print-card{border:0;border-radius:0;}}',
            '</style></head><body>',
            '<div class="print-shell"><div class="print-card">' + source.innerHTML + '</div></div>',
            '<script>window.onload=function(){window.print();};<\/script>',
            '</body></html>'
        ].join(''));
        printWindow.document.close();
    }

    document.querySelectorAll('.js-barcode-svg').forEach(function(svg) {
        if (typeof window.JsBarcode !== 'function') {
            return;
        }

        var barcodeValue = svg.getAttribute('data-barcode-value') || '';
        if (!barcodeValue) {
            return;
        }

        window.JsBarcode(svg, barcodeValue, {
            format: 'CODE39',
            displayValue: false,
            height: parseInt(svg.getAttribute('data-barcode-height') || '80', 10),
            margin: parseInt(svg.getAttribute('data-barcode-margin') || '12', 10),
            lineColor: svg.getAttribute('data-barcode-line-color') || '#f8fafc',
            background: svg.getAttribute('data-barcode-background') || 'transparent',
            width: 2
        });
    });

    document.querySelectorAll('.js-qr-target').forEach(function(target) {
        if (typeof window.qrcode !== 'function') {
            return;
        }

        var qrValue = target.getAttribute('data-qr-value') || '';
        if (!qrValue) {
            return;
        }

        var qrSize = parseInt(target.getAttribute('data-qr-size') || '200', 10);
        var qr = window.qrcode(0, 'M');
        qr.addData(qrValue);
        qr.make();
        target.innerHTML = qr.createSvgTag(4, 1);

        var qrSvg = target.querySelector('svg');
        if (qrSvg) {
            qrSvg.setAttribute('width', String(qrSize));
            qrSvg.setAttribute('height', String(qrSize));
            qrSvg.setAttribute('role', 'img');
        }
    });

    document.querySelectorAll('[data-download-svg-target]').forEach(function(button) {
        button.addEventListener('click', function() {
            downloadSvg(
                this.getAttribute('data-download-svg-target'),
                this.getAttribute('data-download-filename') || 'label.svg'
            );
        });
    });

    document.querySelectorAll('[data-print-source]').forEach(function(button) {
        button.addEventListener('click', function() {
            printLabelCard(
                this.getAttribute('data-print-source'),
                this.getAttribute('data-print-title') || 'Label Print'
            );
        });
    });
});
