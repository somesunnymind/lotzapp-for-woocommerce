/* global jQuery, wp, lotzwooImageManagement */
(function ($) {
    'use strict';

    var config = window.lotzwooImageManagement || {};
    if (typeof wp === 'undefined' || typeof wp.media === 'undefined' || typeof wp.ajax === 'undefined') {
        return;
    }

    if (!config.nonce) {
        return;
    }

    var texts = $.extend(
        {
            featuredTitle: '',
            featuredButton: '',
            galleryTitle: '',
            galleryButton: '',
            errorMessage: '',
            invalidFile: ''
        },
        config.texts || {}
    );

    var ALLOWED_TYPES = ['image/jpeg', 'image/webp'];
    var ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'webp'];
    var draggedGalleryItem = null;

    function parseIds(value) {
        if (!value) {
            return [];
        }
        if (Array.isArray(value)) {
            return value.map(function (id) {
                return parseInt(id, 10) || 0;
            }).filter(function (id) {
                return id > 0;
            });
        }
        return String(value)
            .split(',')
            .map(function (id) {
                return parseInt(id, 10) || 0;
            })
            .filter(function (id) {
                return id > 0;
            });
    }

    function getGalleryIds(cell) {
        var stored = cell.data('galleryIds');
        if (Array.isArray(stored)) {
            return stored;
        }
        var attr = cell.attr('data-gallery-ids') || '';
        var ids = parseIds(attr);
        cell.data('galleryIds', ids);
        return ids;
    }

    function setGalleryIds(cell, ids) {
        var sanitized = parseIds(ids);
        cell.data('galleryIds', sanitized);
        cell.attr('data-gallery-ids', sanitized.join(','));
    }

    function setFeaturedId(cell, id) {
        var sanitized = parseInt(id, 10) || 0;
        cell.attr('data-image-id', sanitized > 0 ? sanitized : '');
    }

    function startLoading(cell) {
        cell.addClass('is-loading');
    }

    function stopLoading(cell) {
        cell.removeClass('is-loading');
    }

    function extractErrorMessage(response) {
        if (!response) {
            return '';
        }
        if (response.responseJSON && response.responseJSON.data && response.responseJSON.data.message) {
            return response.responseJSON.data.message;
        }
        if (response.data && response.data.message) {
            return response.data.message;
        }
        if (response.message) {
            return response.message;
        }
        return '';
    }

    function handleError(response) {
        var base = texts.errorMessage || 'Fehler beim Speichern.';
        var detail = extractErrorMessage(response);
        if (detail) {
            window.alert(base + '\n' + detail);
            if (window.console) {
                window.console.error(detail);
            }
        } else {
            window.alert(base);
        }
    }

    function isDroppableCell(cell) {
        return !!(cell && cell.length && cell.attr('data-field') && cell.attr('data-disabled') !== 'true' && !cell.hasClass('is-loading'));
    }

    function isAllowedFile(file) {
        if (!file) {
            return false;
        }
        var type = (file.type || '').toLowerCase();
        if (type && ALLOWED_TYPES.indexOf(type) !== -1) {
            return true;
        }
        var name = (file.name || '').toLowerCase();
        if (!name) {
            return false;
        }
        return ALLOWED_EXTENSIONS.some(function (ext) {
            return name.substr(-ext.length) === ext;
        });
    }

    function uploadSingleFile(file) {
        var deferred = $.Deferred();
        var formData = new window.FormData();
        formData.append('action', 'lotzwoo_upload_product_media');
        formData.append('nonce', config.nonce);
        formData.append('file', file, file.name);

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json'
        }).done(function (response) {
            if (response && response.success && response.data && response.data.id) {
                deferred.resolve(response.data);
            } else if (response && response.data) {
                deferred.reject(response);
            } else {
                deferred.reject(response || {});
            }
        }).fail(function (xhr) {
            deferred.reject(xhr);
        });

        return deferred.promise();
    }

    function uploadFilesSequential(files) {
        var deferred = $.Deferred();
        var results = [];

        function next(index) {
            if (index >= files.length) {
                deferred.resolve(results);
                return;
            }

            uploadSingleFile(files[index]).done(function (data) {
                results.push(data);
                next(index + 1);
            }).fail(function (error) {
                deferred.reject(error);
            });
        }

        next(0);
        return deferred.promise();
    }

    function submitUpdate(cell, payload) {
        return wp.ajax.post('lotzwoo_update_product_media', payload).done(function (response) {
            if (response && typeof response.html !== 'undefined') {
                cell.find('.lotzwoo-image-management__content').html(response.html);
                if (payload.field === 'featured') {
                    setFeaturedId(cell, response.id || 0);
                } else {
                    setGalleryIds(cell, response.ids || []);
                }
            }

            var row = cell.closest('tr');
            if (payload.field === 'featured') {
                var hasImage = payload.attachmentId && parseInt(payload.attachmentId, 10) > 0 ? 1 : 0;
                row.attr('data-featured-count', hasImage);
                row.data('featuredCount', hasImage);
            } else {
                var galleryCount = Array.isArray(payload.attachmentIds) ? payload.attachmentIds.length : 0;
                row.attr('data-gallery-count', galleryCount);
                row.data('galleryCount', galleryCount);
            }
        });
    }

    function handleFileDrop(cell, fileList) {
        var field = cell.attr('data-field');
        if (!field) {
            return;
        }

        var objectId = parseInt(cell.attr('data-object-id'), 10);
        var objectType = cell.attr('data-object-type');
        if (!objectId || !objectType) {
            return;
        }

        var files = Array.prototype.slice.call(fileList || []);
        var validFiles = files.filter(isAllowedFile);

        if (!validFiles.length) {
            var message = texts.invalidFile || texts.errorMessage || 'Fehler beim Speichern.';
            window.alert(message);
            return;
        }

        if (field === 'featured') {
            validFiles = [validFiles[0]];
        }

        startLoading(cell);

        uploadFilesSequential(validFiles).done(function (results) {
            if (!Array.isArray(results) || !results.length) {
                stopLoading(cell);
                return;
            }

            var payload = {
                nonce: config.nonce,
                objectId: objectId,
                objectType: objectType,
                field: field
            };

            if (field === 'featured') {
                var attachmentId = parseInt(results[0].id, 10) || 0;
                payload.attachmentId = attachmentId;
            } else {
                var existing = getGalleryIds(cell);
                var newIds = results.map(function (item) {
                    return parseInt(item.id, 10) || 0;
                }).filter(function (id) {
                    return id > 0;
                });

                var combined = existing.concat(newIds).filter(function (id, index, arr) {
                    return id > 0 && arr.indexOf(id) === index;
                });
                payload.attachmentIds = combined;
            }

            submitUpdate(cell, payload).fail(function (xhr) {
                handleError(xhr);
            }).always(function () {
                stopLoading(cell);
            });
        }).fail(function (error) {
            handleError(error);
            stopLoading(cell);
        });
    }

    function openFrame(cell) {
        if (cell.attr('data-disabled') === 'true' || cell.hasClass('is-loading')) {
            return;
        }

        var field = cell.attr('data-field');
        if (!field) {
            return;
        }

        var objectId = parseInt(cell.attr('data-object-id'), 10);
        var objectType = cell.attr('data-object-type');
        if (!objectId || !objectType) {
            return;
        }

        var isGallery = field === 'gallery';
        var frame = cell.data('mediaFrame');

        if (frame) {
            frame.open();
            return;
        }

        frame = wp.media({
            title: isGallery ? texts.galleryTitle : texts.featuredTitle,
            button: {
                text: isGallery ? texts.galleryButton : texts.featuredButton
            },
            library: {
                type: 'image'
            },
            multiple: isGallery ? 'add' : false
        });

        frame.on('open', function () {
            var selection = frame.state().get('selection');
            if (!selection) {
                return;
            }

            if (isGallery) {
                var ids = getGalleryIds(cell);
                if (!ids.length) {
                    return;
                }
                selection.reset();
                ids.forEach(function (id) {
                    var attachment = wp.media.attachment(id);
                    if (attachment) {
                        attachment.fetch();
                        selection.add(attachment);
                    }
                });
            } else {
                var currentId = parseInt(cell.attr('data-image-id'), 10);
                if (!currentId) {
                    return;
                }
                var attachment = wp.media.attachment(currentId);
                if (attachment) {
                    attachment.fetch();
                    selection.reset([attachment]);
                }
            }
        });

        frame.on('select', function () {
            var selection = frame.state().get('selection');
            if (!selection) {
                return;
            }

            var payload = {
                nonce: config.nonce,
                objectId: objectId,
                objectType: objectType,
                field: isGallery ? 'gallery' : 'featured'
            };

            if (isGallery) {
                var galleryIds = [];
                selection.each(function (model) {
                    var id = model.get('id');
                    if (id) {
                        galleryIds.push(parseInt(id, 10));
                    }
                });
                payload.attachmentIds = galleryIds;
            } else {
                var first = selection.first();
                payload.attachmentId = first ? parseInt(first.get('id'), 10) || 0 : 0;
            }

            startLoading(cell);
            submitUpdate(cell, payload).fail(function (xhr) {
                handleError(xhr);
            }).always(function () {
                stopLoading(cell);
            });
        });

        cell.data('mediaFrame', frame);
        frame.open();
    }

    function handleActivation(event) {
        var cell = $(event.currentTarget);
        if (cell.hasClass('is-reordering')) {
            event.preventDefault();
            return;
        }
        event.preventDefault();
        openFrame(cell);
    }

    function stopEvent(event) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }
    }

    function handleDragEnter(event) {
        var cell = $(event.currentTarget).closest('td');
        if (!isDroppableCell(cell)) {
            return;
        }
        stopEvent(event);
        cell.addClass('is-dragover');
    }

    function handleDragOver(event) {
        var cell = $(event.currentTarget).closest('td');
        if (!isDroppableCell(cell)) {
            return;
        }
        stopEvent(event);
        cell.addClass('is-dragover');
    }

    function handleDragLeave(event) {
        var cell = $(event.currentTarget).closest('td');
        if (!isDroppableCell(cell)) {
            return;
        }
        stopEvent(event);
        if (event.currentTarget && event.relatedTarget && event.currentTarget.contains(event.relatedTarget)) {
            return;
        }
        cell.removeClass('is-dragover');
    }

    function handleDrop(event) {
        var cell = $(event.currentTarget).closest('td');
        if (!isDroppableCell(cell)) {
            return;
        }
        stopEvent(event);
        cell.removeClass('is-dragover');

        var transfer = event.originalEvent && event.originalEvent.dataTransfer;
        if (!transfer || !transfer.files || !transfer.files.length) {
            return;
        }
        handleFileDrop(cell, transfer.files);
    }

    $(document).on('click', '.lotzwoo-image-management__table td[data-field]', handleActivation);

    $(document).on('keydown', '.lotzwoo-image-management__table td[data-field]', function (event) {
        if (event.key === 'Enter' || event.key === ' ') {
            handleActivation(event);
        }
    });

    $(document).on('dragenter', '.lotzwoo-image-management__content', handleDragEnter);
    $(document).on('dragover', '.lotzwoo-image-management__content', handleDragOver);
    $(document).on('dragleave dragend', '.lotzwoo-image-management__content', handleDragLeave);
    $(document).on('drop', '.lotzwoo-image-management__content', handleDrop);
    function handleRemoveClick(event) {
        stopEvent(event);
        var button = $(event.currentTarget);
        var cell = button.closest('td[data-field]');
        if (!cell.length || cell.attr('data-disabled') === 'true' || cell.hasClass('is-loading')) {
            return;
        }

        var field = cell.attr('data-field');
        var objectId = parseInt(cell.attr('data-object-id'), 10);
        var objectType = cell.attr('data-object-type');
        if (!objectId || !objectType) {
            return;
        }

        startLoading(cell);

        if (field === "featured") {
            submitUpdate(cell, {
                nonce: config.nonce,
                objectId: objectId,
                objectType: objectType,
                field: 'featured',
                attachmentId: 0
            }).fail(function (xhr) {
                handleError(xhr);
            }).always(function () {
                stopLoading(cell);
            });
            return;
        }

        if (field === "gallery") {
            var attachmentId = parseInt(button.data('attachmentId'), 10);
            if (!attachmentId) {
                var container = button.closest('.lotzwoo-image-management__gallery-item');
                attachmentId = container.length ? parseInt(container.data('attachmentId'), 10) : 0;
            }

            var ids = getGalleryIds(cell).filter(function (id) {
                return id !== attachmentId;
            });
            setGalleryIds(cell, ids);

            submitUpdate(cell, {
                nonce: config.nonce,
                objectId: objectId,
                objectType: objectType,
                field: 'gallery',
                attachmentIds: ids
            }).fail(function (xhr) {
                handleError(xhr);
            }).always(function () {
                stopLoading(cell);
            });
        }
    }

    $(document).on('click', '.lotzwoo-image-management__remove', handleRemoveClick);

    initSorting();
    initGalleryDragAndDrop();

    function initSorting() {
        var table = $('.lotzwoo-image-management__table');
        if (!table.length) {
            return;
        }

        var tbody = table.find('tbody');
        var sortButtons = table.find('.lotzwoo-image-management__sort');
        if (!sortButtons.length) {
            return;
        }

        var originalGroupOrder = getGroupOrder();
        var state = {
            key: null,
            direction: null
        };

        setDefaultSort('name');
        originalGroupOrder = getGroupOrder();

        sortButtons.on('click', function () {
            var button = $(this);
            var key = button.data('sortKey');
            var newDirection;

            if (state.key === key) {
                if (state.direction === 'asc') {
                    newDirection = 'desc';
                } else if (state.direction === 'desc') {
                    newDirection = null;
                } else {
                    newDirection = 'asc';
                }
            } else {
                newDirection = 'asc';
            }

            state.key = newDirection ? key : null;
            state.direction = newDirection;
            sortButtons.removeClass('is-asc is-desc').data('sortDirection', null);

            if (!newDirection) {
                applyGroupOrder(originalGroupOrder);
                return;
            }

            button.data('sortDirection', newDirection);
            button.addClass(newDirection === 'asc' ? 'is-asc' : 'is-desc');
            applySort(key, newDirection);
        });

        function getGroupOrder() {
            var order = [];
            var seen = {};
            tbody.find('tr').each(function () {
                var row = $(this);
                var groupId = row.data('groupId');
                if (groupId === undefined || seen[groupId]) {
                    return;
                }
                seen[groupId] = true;
                order.push(groupId);
            });
            return order;
        }

        function buildGroups() {
            var groups = [];
            var map = {};

            tbody.find('tr').each(function () {
                var row = $(this);
                var groupId = row.data('groupId');
                if (groupId === undefined) {
                    return;
                }
                if (!map[groupId]) {
                    map[groupId] = {
                        id: groupId,
                        rows: [],
                        metrics: {
                            name: '',
                            type: '',
                            featured: 0,
                            gallery: 0,
                            order: parseInt(row.data('groupOrder'), 10) || 0
                        }
                    };
                    groups.push(map[groupId]);
                }
                var group = map[groupId];
                group.rows.push(row);

                var isParent = parseInt(row.data('isParent'), 10) === 1;
                var rowName = (row.data('name') || '').toString();
                var rowType = (row.data('type') || '').toString();
                var featuredCount = parseInt(row.data('featuredCount'), 10) || 0;
                var galleryCount = parseInt(row.data('galleryCount'), 10) || 0;

                if (isParent || !group.metrics.name) {
                    group.metrics.name = rowName.toLowerCase();
                }
                if (isParent || !group.metrics.type) {
                    group.metrics.type = rowType.toLowerCase();
                }
                if (isParent) {
                    group.metrics.featuredParent = featuredCount;
                    group.metrics.galleryParent = galleryCount;
                }

                group.metrics.featured = Math.max(group.metrics.featured || 0, featuredCount);
                group.metrics.gallery = Math.max(group.metrics.gallery || 0, galleryCount);
            });

            return groups;
        }

        function applyGroupOrder(orderIds) {
            var map = {};
            var groups = buildGroups();
            groups.forEach(function (group) {
                map[group.id] = group;
            });

            var fragment = $(document.createDocumentFragment());
            orderIds.forEach(function (groupId) {
                var group = map[groupId];
                if (!group) {
                    return;
                }
                group.rows.forEach(function (row) {
                    fragment.append(row);
                });
            });
            tbody.append(fragment);
        }

        function setDefaultSort(key) {
            var button = sortButtons.filter(function () {
                return $(this).data('sortKey') === key;
            }).first();
            if (!button.length) {
                return;
            }
            sortButtons.removeClass('is-asc is-desc').data('sortDirection', null);
            state.key = key;
            state.direction = 'asc';
            button.data('sortDirection', 'asc').addClass('is-asc');
            applySort(key, 'asc');
        }

        function applySort(key, direction) {
            var groups = buildGroups();
            var comparator;

            switch (key) {
                case 'name':
                    comparator = function (a, b) {
                        return a.metrics.name.localeCompare(b.metrics.name);
                    };
                    break;
                case 'type':
                    comparator = function (a, b) {
                        return a.metrics.type.localeCompare(b.metrics.type);
                    };
                    break;
                case 'featured':
                    comparator = function (a, b) {
                        return a.metrics.featured - b.metrics.featured;
                    };
                    break;
                case 'gallery':
                    comparator = function (a, b) {
                        return a.metrics.gallery - b.metrics.gallery;
                    };
                    break;
                default:
                    comparator = function (a, b) {
                        return (a.metrics.order || 0) - (b.metrics.order || 0);
                    };
            }

            groups.sort(function (a, b) {
                var result = comparator(a, b);
                if (result === 0) {
                    return (a.metrics.order || 0) - (b.metrics.order || 0);
                }
                return result;
            });
            if (direction === 'desc') {
                groups.reverse();
            }

            var fragment = $(document.createDocumentFragment());
            groups.forEach(function (group) {
                group.rows.forEach(function (row) {
                    fragment.append(row);
                });
            });
            tbody.append(fragment);
        }
    }

    function initGalleryDragAndDrop() {
        $(document).on('dragstart', '.lotzwoo-image-management__gallery-item', function (event) {
            var cell = $(this).closest('td[data-field="gallery"]');
            if (!cell.length || cell.attr('data-disabled') === 'true' || cell.hasClass('is-loading')) {
                event.preventDefault();
                return;
            }
            draggedGalleryItem = $(this);
            cell.addClass('is-reordering');
            draggedGalleryItem.addClass('is-dragging');
            if (event.originalEvent && event.originalEvent.dataTransfer) {
                event.originalEvent.dataTransfer.effectAllowed = 'move';
                event.originalEvent.dataTransfer.setData('text/plain', 'drag');
            }
        });

        $(document).on('dragend', '.lotzwoo-image-management__gallery-item', function () {
            var cell = $(this).closest('td[data-field="gallery"]');
            $(this).removeClass('is-dragging');
            if (cell.length) {
                cell.removeClass('is-reordering');
            }
            draggedGalleryItem = null;
        });

        $(document).on('dragover', '.lotzwoo-image-management__gallery-item, .lotzwoo-image-management__gallery', function (event) {
            var cell = $(this).closest('td[data-field="gallery"]');
            if (!cell.length || cell.attr('data-disabled') === 'true' || cell.hasClass('is-loading')) {
                return;
            }
            event.preventDefault();
            if (event.originalEvent && event.originalEvent.dataTransfer) {
                event.originalEvent.dataTransfer.dropEffect = 'move';
            }
        });

        $(document).on('drop', '.lotzwoo-image-management__gallery-item', function (event) {
            var targetItem = $(this);
            var cell = targetItem.closest('td[data-field="gallery"]');
            if (!cell.length || !draggedGalleryItem || cell.hasClass('is-loading')) {
                return;
            }
            stopEvent(event);
            if (targetItem[0] === draggedGalleryItem[0]) {
                finalizeGalleryOrder(cell, false);
                return;
            }

            var rect = targetItem[0].getBoundingClientRect();
            var insertBefore = event.originalEvent.clientX < rect.left + rect.width / 2;

            if (insertBefore) {
                draggedGalleryItem.insertBefore(targetItem);
            } else {
                draggedGalleryItem.insertAfter(targetItem);
            }

            finalizeGalleryOrder(cell, true);
        });

        $(document).on('drop', '.lotzwoo-image-management__gallery', function (event) {
            var container = $(this);
            var cell = container.closest('td[data-field="gallery"]');
            if (!cell.length || !draggedGalleryItem || cell.hasClass('is-loading')) {
                return;
            }
            stopEvent(event);
            container.append(draggedGalleryItem);
            finalizeGalleryOrder(cell, true);
        });
    }

    function finalizeGalleryOrder(cell, shouldSave) {
        if (!cell || !cell.length) {
            draggedGalleryItem = null;
            return;
        }

        var ids = [];
        cell.find('.lotzwoo-image-management__gallery-item').each(function () {
            var id = parseInt($(this).data('attachmentId'), 10);
            if (id > 0) {
                ids.push(id);
            }
        });

        var current = getGalleryIds(cell);

        cell.removeClass('is-reordering');
        if (draggedGalleryItem) {
            draggedGalleryItem.removeClass('is-dragging');
            draggedGalleryItem = null;
        }

        if (!shouldSave || JSON.stringify(ids) === JSON.stringify(current)) {
            return;
        }

        setGalleryIds(cell, ids);

        var objectId = parseInt(cell.attr('data-object-id'), 10);
        var objectType = cell.attr('data-object-type');
        if (!objectId || !objectType) {
            return;
        }

        startLoading(cell);

        submitUpdate(cell, {
            nonce: config.nonce,
            objectId: objectId,
            objectType: objectType,
            field: 'gallery',
            attachmentIds: ids
        }).fail(function (xhr) {
            handleError(xhr);
        }).always(function () {
            stopLoading(cell);
        });
    }
})(jQuery);


