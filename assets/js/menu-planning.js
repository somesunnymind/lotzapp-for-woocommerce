(function () {
    const config = window.lotzwooMenuPlanning;
    if (!config) {
        return;
    }
    const allowProductEditLinks = !!Number(config.showProductEditLinks);

    const roots = document.querySelectorAll('[data-lotzwoo-menu-planning]');
    if (!roots.length) {
        return;
    }

    roots.forEach((root) => initPlanner(root));

    function initPlanner(root) {
        const datasetState = parseJSON(root.getAttribute('data-initial'));
        const initialState = Object.assign(
            {
                entries: [],
                historyEntries: [],
                tags: [],
                schedule: null,
                tableExists: true,
                needsMigration: false,
            },
            config.initialState || {},
            datasetState || {}
        );

        const state = {
            entries: [],
            history: [],
            tags: initialState.tags || [],
            schedule: initialState.schedule || null,
            loading: false,
            error: '',
            tableExists: initialState.tableExists !== false,
            needsMigration: Boolean(initialState.needsMigration),
            creating: false,
            activeTab: 'current',
        };

        state.entries = hydrateEntries(initialState.entries || []);
        state.history = hydrateEntries(initialState.historyEntries || []);
        render();

        // Ensure fresh data after initial paint.
        fetchList(true);

        /**
         * Helpers
         */
        function hydrateEntries(entries) {
            if (!Array.isArray(entries)) {
                return [];
            }
            return entries.map((entry) => hydrateEntry(entry));
        }

        function hydrateEntry(entry) {
            const payload = entry && entry.payload ? entry.payload : {};
            return Object.assign({}, entry, {
                payload,
                draftPayload: createDraftPayload(payload),
                dirty: false,
                saving: false,
                deleting: false,
                runningNow: false,
            });
        }

        function createDraftPayload(payload) {
            const normalized = {};
            const slugs =
                state.tags.length > 0
                    ? state.tags.map((tag) => tag.slug)
                    : Object.keys(payload || {});

            slugs.forEach((slug) => {
                const values = Array.isArray(payload && payload[slug]) ? payload[slug] : [];
                normalized[slug] = values
                    .map((value) => parseInt(value, 10))
                    .filter((value) => Number.isFinite(value) && value > 0);
            });

            return normalized;
        }

        function clonePayload(payload) {
            const clone = {};
            Object.keys(payload || {}).forEach((slug) => {
                const values = Array.isArray(payload[slug]) ? payload[slug] : [];
                clone[slug] = values
                    .map((value) => parseInt(value, 10))
                    .filter((value) => Number.isFinite(value) && value > 0);
            });
            return clone;
        }

        function fetchList(showSpinner) {
            if (showSpinner) {
                state.loading = true;
                state.error = '';
                render();
            }

            apiGet('lotzwoo_menu_plan_list')
                .then((data) => {
                    state.tableExists = !data.needsMigration;
                    state.needsMigration = Boolean(data.needsMigration);
                    state.tags = Array.isArray(data.tags) ? data.tags : [];
                    state.schedule = data.schedule || null;
                    state.entries = hydrateEntries(data.entries || []);
                    state.history = hydrateEntries(data.history || []);
                    state.loading = false;
                    state.error = '';
                    render();
                })
                .catch((error) => {
                    state.loading = false;
                    state.error = error.message || config.i18n.errorGeneric;
                    render();
                });
        }

        function handleCreate() {
            if (state.creating) {
                return;
            }
            state.creating = true;
            state.error = '';
            render();

            apiPost('lotzwoo_menu_plan_create')
                .then((data) => {
                    if (data.schedule) {
                        state.schedule = data.schedule;
                    }
                    state.creating = false;
                    state.error = '';
                    fetchList(false);
                })
                .catch((error) => {
                    state.creating = false;
                    state.error = error.message || config.i18n.errorGeneric;
                    render();
                });
        }

        function handleSave(entryId) {
            const entry = state.entries.find((item) => item.id === entryId);
            if (!entry || entry.saving || !entry.dirty) {
                return;
            }

            entry.saving = true;
            state.error = '';
            render();

            apiPost('lotzwoo_menu_plan_update', {
                id: entryId,
                payload: JSON.stringify(entry.draftPayload || {}),
            })
                .then(() => {
                    entry.payload = clonePayload(entry.draftPayload);
                    entry.draftPayload = createDraftPayload(entry.payload);
                    entry.saving = false;
                    entry.dirty = false;
                    state.error = '';
                    render();
                })
                .catch((error) => {
                    entry.saving = false;
                    state.error = error.message || config.i18n.errorGeneric;
                    render();
                });
        }

        function handleDelete(entryId) {
            const entry = state.entries.find((item) => item.id === entryId);
            if (!entry || entry.deleting) {
                return;
            }

            if (!window.confirm(config.i18n.confirmRemove || 'Wirklich entfernen?')) {
                return;
            }

            entry.deleting = true;
            state.error = '';
            render();

            apiPost('lotzwoo_menu_plan_delete', { id: entryId })
                .then(() => {
                    entry.deleting = false;
                    state.error = '';
                    fetchList(false);
                })
                .catch((error) => {
                    entry.deleting = false;
                    state.error = error.message || config.i18n.errorGeneric;
                    render();
                });
        }

        function handleRunNow(entryId) {
            const entry = state.entries.find((item) => item.id === entryId);
            if (!entry || entry.runningNow) {
                return;
            }

            entry.runningNow = true;
            state.error = '';
            render();

            apiPost('lotzwoo_menu_plan_run_now', {
                id: entryId,
                payload: JSON.stringify(entry.draftPayload || {}),
            })
                .then((data) => {
                    entry.runningNow = false;
                    entry.payload = clonePayload(entry.draftPayload);
                    entry.draftPayload = createDraftPayload(entry.payload);
                    entry.dirty = false;
                    if (data.schedule) {
                        state.schedule = data.schedule;
                    }
                    state.error = '';
                    render();
                    fetchList(false);
                })
                .catch((error) => {
                    entry.runningNow = false;
                    state.error = error.message || config.i18n.errorGeneric;
                    render();
                });
        }

        function handleSelectChange(entryId, tagSlug, values) {
            const entry = state.entries.find((item) => item.id === entryId);
            if (!entry) {
                return;
            }

            entry.draftPayload[tagSlug] = values;
            entry.dirty = true;
            render();
        }

        /**
         * Rendering
         */
        function render() {
            root.innerHTML = '';

            if (state.error) {
                const errorBox = document.createElement('div');
                errorBox.className = 'lotzwoo-menu-planning__error';
                errorBox.textContent = state.error;
                root.appendChild(errorBox);
            }

            if (state.needsMigration) {
                const notice = document.createElement('div');
                notice.className = 'lotzwoo-menu-planning__notice';
                notice.textContent = config.i18n.tableMissing;
                root.appendChild(notice);
            }

            const tabs = document.createElement('div');
            tabs.className = 'lotzwoo-menu-planning__tabs';
            [
                { id: 'current', label: config.i18n.tabCurrent || 'Aktuelle & geplante MenÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¼plÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¤ne' },
                { id: 'history', label: config.i18n.tabHistory || 'Vergangene MenÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¼plÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¤ne' },
            ].forEach((tab) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'lotzwoo-menu-planning__tab button' + (state.activeTab === tab.id ? ' lotzwoo-menu-planning__tab--active' : '');
                button.textContent = tab.label;
                button.addEventListener('click', () => {
                    if (state.activeTab !== tab.id) {
                        state.activeTab = tab.id;
                        render();
                    }
                });
                tabs.appendChild(button);
            });
            root.appendChild(tabs);

            const panels = document.createElement('div');
            panels.className = 'lotzwoo-menu-planning__tab-panels';

            const currentWrapper = document.createElement('div');
            currentWrapper.className = 'lotzwoo-menu-planning__tab-panel lotzwoo-menu-planning__tab-panel--current';
            currentWrapper.dataset.tab = 'current';
            panels.appendChild(currentWrapper);

            const historyWrapper = document.createElement('div');
            historyWrapper.className = 'lotzwoo-menu-planning__tab-panel lotzwoo-menu-planning__tab-panel--history';
            historyWrapper.dataset.tab = 'history';
            panels.appendChild(historyWrapper);

            root.appendChild(panels);

            if (state.activeTab === 'history') {
                currentWrapper.hidden = true;
                historyWrapper.hidden = false;
                renderHistorySection(historyWrapper);
                return;
            }

            historyWrapper.hidden = true;
            currentWrapper.hidden = false;
            renderCurrentSection(currentWrapper);
        }

        function renderCurrentSection(container) {
            container.innerHTML = '';

            const toolbar = document.createElement('div');
            toolbar.className = 'lotzwoo-menu-planning__toolbar';

            const createButton = document.createElement('button');
            createButton.type = 'button';
            createButton.className = 'button button-primary lotzwoo-menu-planning__create';
            createButton.textContent = config.i18n.createButton;
            createButton.disabled = !state.tableExists || state.creating;
            createButton.addEventListener('click', handleCreate);
            toolbar.appendChild(createButton);

            if (state.schedule) {
                const info = document.createElement('div');
                info.className = 'lotzwoo-menu-planning__schedule-info';
                const scheduleLabel = formatScheduleLabel(state.schedule);
                info.innerHTML = `
                    <strong>${escapeHtml(scheduleLabel)}</strong>
                    <span>${escapeHtml(state.schedule.nextSlotDisplay || state.schedule.nextSlotLocal || '')}</span>
                `;
                toolbar.appendChild(info);
            }

            container.appendChild(toolbar);

            if (state.loading) {
                const loading = document.createElement('div');
                loading.className = 'lotzwoo-menu-planning__loading';
                loading.textContent = config.i18n.loading;
                container.appendChild(loading);
                return;
            }

            if (!state.entries.length) {
                const empty = document.createElement('div');
                empty.className = 'lotzwoo-menu-planning__empty';
                empty.textContent = config.i18n.empty;
                container.appendChild(empty);
                return;
            }

            const table = document.createElement('table');
            table.className = 'lotzwoo-menu-planning__table wp-list-table widefat striped';

            const thead = document.createElement('thead');
            const headerRow = document.createElement('tr');

            const timeHeader = document.createElement('th');
            timeHeader.textContent = config.i18n.timeColumn;
            headerRow.appendChild(timeHeader);

            state.tags.forEach((tag) => {
                const th = document.createElement('th');
                th.textContent = tag.name || tag.slug;
                headerRow.appendChild(th);
            });

            const actionsHeader = document.createElement('th');
            actionsHeader.textContent = config.i18n.actionsColumn;
            headerRow.appendChild(actionsHeader);

            thead.appendChild(headerRow);
            table.appendChild(thead);

            const tbody = document.createElement('tbody');
            state.entries.forEach((entry) => {
                tbody.appendChild(renderRow(entry));
            });
            table.appendChild(tbody);

            container.appendChild(table);
        }

        function renderRow(entry) {
            const tr = document.createElement('tr');

            const timeCell = document.createElement('td');
            timeCell.className = 'lotzwoo-menu-planning__cell lotzwoo-menu-planning__cell--time';
            const countdownLabel = getCountdownLabel(entry);
            const timeBlock = buildEntryTimeMarkup(entry);
            const timeParts = [
                timeBlock,
                `<div class="lotzwoo-menu-planning__status">${formatStatus(entry)}</div>`,
            ];
            if (countdownLabel) {
                timeParts.push(
                    `<div class="lotzwoo-menu-planning__status-detail">${escapeHtml(countdownLabel)}</div>`
                );
            }
            timeCell.innerHTML = timeParts.join('');
            tr.appendChild(timeCell);

            state.tags.forEach((tag) => {
                const cell = document.createElement('td');
                cell.className = 'lotzwoo-menu-planning__cell';
                const products = Array.isArray(tag.products) ? tag.products : [];

                if (!products.length) {
                    const placeholder = document.createElement('em');
                    placeholder.className = 'lotzwoo-menu-planning__no-products';
                    placeholder.textContent = config.i18n.noProducts || 'Keine Produkte vorhanden.';
                    cell.appendChild(placeholder);
                } else {
                    const select = document.createElement('select');
                    select.multiple = true;
                    select.dataset.placeholder = config.i18n.addProduct || 'Produkt hinzufügen';
                    select.dataset.tagSlug = tag.slug || '';
                    select.dataset.tagName = tag.name || tag.slug || '';
                    select.dataset.categorySlug = tag.category_slug || '';
                    products.forEach((product) => {
                        const option = document.createElement('option');
                        option.value = String(product.id);
                        option.textContent = product.name;
                        option.dataset.tags = JSON.stringify(Array.isArray(product.tags) ? product.tags : []);
                        option.dataset.permalink = product.permalink ? String(product.permalink) : '';
                        option.dataset.editUrl = product.edit_url ? String(product.edit_url) : '';
                        option.dataset.sku = product.sku ? String(product.sku) : '';
                        const selectedValues = entry.draftPayload[tag.slug] || [];
                        option.selected = selectedValues.includes(product.id);
                        select.appendChild(option);
                    });

                    select.disabled = entry.saving || entry.deleting;
                    cell.appendChild(select);

                    const detailHost = document.createElement('div');
                    detailHost.className = 'lotzwoo-menu-planning__detail-host';
                    cell.appendChild(detailHost);

                    initializeTomSelect(select, entry, tag, detailHost);
                }

                tr.appendChild(cell);
            });

            const actionCell = document.createElement('td');
            actionCell.className = 'lotzwoo-menu-planning__cell lotzwoo-menu-planning__cell--actions';

            const deleteButton = document.createElement('button');
            deleteButton.type = 'button';
            deleteButton.className = 'button button-link-delete';
            deleteButton.textContent = config.i18n.remove;
            deleteButton.disabled = entry.deleting || entry.saving;
            deleteButton.addEventListener('click', () => handleDelete(entry.id));

            if (entry.is_active || entry.is_current) {
                const runButton = document.createElement('button');
                runButton.type = 'button';
                runButton.className = 'button button-secondary';
                runButton.textContent = entry.runningNow ? config.i18n.applyingNow : config.i18n.applyNow;
                runButton.disabled = entry.runningNow || entry.saving || entry.deleting || !entry.dirty;
                runButton.addEventListener('click', () => handleRunNow(entry.id));
                actionCell.appendChild(runButton);
            } else {
                const saveButton = document.createElement('button');
                saveButton.type = 'button';
                saveButton.className = 'button button-secondary';
                saveButton.textContent = config.i18n.save;
                saveButton.disabled = !entry.dirty || entry.saving || entry.deleting;
                saveButton.addEventListener('click', () => handleSave(entry.id));
                actionCell.appendChild(saveButton);
            }
            actionCell.appendChild(deleteButton);

            tr.appendChild(actionCell);
            applyRowState(tr, entry);
            return tr;
        }

        function applyRowState(row, entry) {
            const busy = entry.saving || entry.deleting || entry.runningNow;
            if (busy) {
                row.classList.add('lotzwoo-menu-planning__row--busy');
            } else {
                row.classList.remove('lotzwoo-menu-planning__row--busy');
            }
        }

        function renderHistorySection(container) {
            const target = container || document.createElement('div');
            if (!container) {
                root.appendChild(target);
            }
            target.classList.add('lotzwoo-menu-planning__history');
            target.innerHTML = '';

            if (state.loading) {
                const loading = document.createElement('div');
                loading.className = 'lotzwoo-menu-planning__loading';
                loading.textContent = config.i18n.loading;
                target.appendChild(loading);
                return;
            }

            if (!state.history.length) {
                const empty = document.createElement('div');
                empty.className = 'lotzwoo-menu-planning__empty';
                empty.textContent = config.i18n.historyEmpty || 'Keine vergangenen MenÃƒÆ’Ã‚Â¼plÃƒÆ’Ã‚Â¤ne vorhanden.';
                target.appendChild(empty);
                return;
            }

            const table = document.createElement('table');
            table.className = 'lotzwoo-menu-planning__table wp-list-table widefat striped';

            const thead = document.createElement('thead');
            const headerRow = document.createElement('tr');

            const timeHeader = document.createElement('th');
            timeHeader.textContent = config.i18n.timeColumn;
            headerRow.appendChild(timeHeader);

            const statusHeader = document.createElement('th');
            statusHeader.textContent = config.i18n.statusColumn || config.i18n.actionsColumn;
            headerRow.appendChild(statusHeader);

            const detailsHeader = document.createElement('th');
            detailsHeader.textContent = config.i18n.assignmentsColumn || 'Zuordnungen';
            headerRow.appendChild(detailsHeader);

            thead.appendChild(headerRow);
            table.appendChild(thead);

            const tbody = document.createElement('tbody');
            state.history.forEach((entry) => {
                const tr = document.createElement('tr');

                const timeCell = document.createElement('td');
                timeCell.innerHTML = buildEntryTimeMarkup(entry);
                tr.appendChild(timeCell);

                const statusCell = document.createElement('td');
                statusCell.textContent = formatStatus(entry);
                tr.appendChild(statusCell);

                const detailsCell = document.createElement('td');
                detailsCell.textContent = formatAssignments(entry);
                tr.appendChild(detailsCell);

                tbody.appendChild(tr);
            });

            table.appendChild(tbody);
            target.appendChild(table);
        }

        function formatAssignments(entry) {
            const payload = entry && entry.payload ? entry.payload : {};
            const parts = [];
            Object.keys(payload).forEach((slug) => {
                const ids = Array.isArray(payload[slug]) ? payload[slug] : [];
                if (!ids.length) {
                    return;
                }
                const tag = findTagBySlug(slug);
                const tagName = tag ? tag.name : slug;
                const names = ids.map((id) => {
                    const productId = parseInt(id, 10);
                    const product =
                        tag && Array.isArray(tag.products)
                            ? tag.products.find((item) => parseInt(item.id, 10) === productId)
                            : null;
                    return product ? product.name : '#' + productId;
                });
                parts.push(tagName + ': ' + names.join(', '));
            });
            if (!parts.length) {
                return config.i18n.historyAssignmentsEmpty || 'Keine Produktzuordnungen gespeichert.';
            }
            return parts.join(' | ');
        }

        function findTagBySlug(slug) {
            return state.tags.find((tag) => tag.slug === slug);
        }

        function initializeTomSelect(select, entry, tag, detailHost) {
            if (typeof TomSelect === 'undefined' || !select || select.tomselect) {
                return;
            }

            const productMap = getProductMap(tag);
            const instance = new TomSelect(select, {
                plugins: ['remove_button'],
                create: false,
                maxOptions: null,
                closeAfterSelect: false,
                render: {
                    item: function (data, escape) {
                        const tags = extractTagNames(data);
                        const pills = renderTagPills(tags, escape);
                        return (
                            '<div class="lotzwoo-menu-planning__ts-item">' +
                            '<span class="lotzwoo-menu-planning__ts-item-label">' +
                            escape(data.text || '') +
                            '</span>' +
                            pills +
                            '</div>'
                        );
                    },
                    option: function (data, escape) {
                        const tags = extractTagNames(data);
                        const pills = renderTagPills(tags, escape);
                        return (
                            '<div class="lotzwoo-menu-planning__ts-option">' +
                            '<span class="lotzwoo-menu-planning__ts-item-label">' +
                            escape(data.text || '') +
                            '</span>' +
                            pills +
                            '</div>'
                        );
                    },
                    no_results: function (data, escape) {
                        return '<div class="no-results">' + escape(config.i18n.noProducts || 'Keine passenden Produkte.') + '</div>';
                    },
                },
                onChange(values) {
                    const parsed = (values || []).map((value) => parseInt(value, 10)).filter((value) => Number.isFinite(value));
                    handleSelectChange(entry.id, tag.slug, parsed);
                },
                onItemSelect(event, item) {
                    if (event && event.target && event.target.closest('.remove')) {
                        return;
                    }
                    event.preventDefault();
                    this.blur();
                    return false;
                },
            });

            if (entry.saving || entry.deleting) {
                instance.disable();
            } else {
                instance.enable();
            }
            bindItemDetails(instance, tag, detailHost);

            try {
                const detail = {
                    select,
                    instance,
                    tagSlug: tag && tag.slug ? tag.slug : select.dataset.tagSlug || '',
                    tagName: tag && tag.name ? tag.name : select.dataset.tagName || '',
                    categorySlug: tag && tag.category_slug ? tag.category_slug : select.dataset.categorySlug || '',
                };
                document.dispatchEvent(new CustomEvent('lotzwoo:tomselect-init', { detail }));
            } catch (error) {
                // no-op
            }

            function extractTagNames(data) {
                const source =
                    (data && data.$option && data.$option.dataset && data.$option.dataset.tags) ||
                    (Array.isArray(data && data.tags) ? data.tags : null);

                if (!source) {
                    return [];
                }

                let parsed = source;
                if (typeof source === 'string') {
                    try {
                        parsed = JSON.parse(source);
                    } catch (error) {
                        parsed = [];
                    }
                }

                if (!Array.isArray(parsed)) {
                    return [];
                }

                return parsed
                    .map((tag) => {
                        if (typeof tag === 'string') {
                            return tag;
                        }
                        if (tag && typeof tag === 'object' && tag.name) {
                            return String(tag.name);
                        }
                        if (tag && typeof tag === 'object' && tag.slug) {
                            return String(tag.slug);
                        }
                        return '';
                    })
                    .filter(Boolean);
            }

            function renderTagPills(tagNames, escape) {
                if (!tagNames.length) {
                    return '';
                }
                const pills = tagNames
                    .map((name) => '<span class="lotzwoo-menu-planning__tag-pill">' + escape(name) + '</span>')
                    .join('');
                return '<span class="lotzwoo-menu-planning__tag-pills">' + pills + '</span>';
            }
        }

        function getProductMap(tag) {
            if (!tag || !Array.isArray(tag.products)) {
                return new Map();
            }
            if (!tag.__lotzwooProductMap) {
                const map = new Map();
                tag.products.forEach((product) => {
                    if (!product || typeof product.id === 'undefined') {
                        return;
                    }
                    map.set(String(product.id), product);
                });
                tag.__lotzwooProductMap = map;
            }
            return tag.__lotzwooProductMap;
        }

        function resolveEventTarget(event) {
            if (!event) {
                return null;
            }
            if (event.target && event.target.nodeType === 1) {
                return event.target;
            }
            if (event.srcElement && event.srcElement.nodeType === 1) {
                return event.srcElement;
            }
            if (typeof event.composedPath === 'function') {
                const path = event.composedPath();
                if (Array.isArray(path)) {
                    for (let i = 0; i < path.length; i += 1) {
                        const node = path[i];
                        if (node && node.nodeType === 1) {
                            return node;
                        }
                    }
                }
            }
            return null;
        }

        function bindItemDetails(instance, tag, detailHost) {
            if (!instance || !tag || !instance.wrapper || !detailHost) {
                return;
            }
            const wrapper = instance.wrapper;
            if (wrapper.dataset.lotzwooDetailsBound === '1') {
                return;
            }
            wrapper.dataset.lotzwooDetailsBound = '1';
            const productMap = getProductMap(tag);

            wrapper.addEventListener(
                'click',
                (event) => {
                    const target = resolveEventTarget(event);
                    if (target && target.closest('.lotzwoo-menu-planning__ts-item-links a')) {
                        event.stopPropagation();
                    }
                },
                true
            );

            wrapper.addEventListener('click', (event) => {
                const target = resolveEventTarget(event);
                if (!target) {
                    return;
                }
                if (target.closest('.remove')) {
                    return;
                }
                if (target.closest('.lotzwoo-menu-planning__ts-item-links a')) {
                    return;
                }
                const itemContent = target.closest('.lotzwoo-menu-planning__ts-item');
                if (!itemContent) {
                    return;
                }
                const item = itemContent.classList.contains('item') ? itemContent : itemContent.closest('.item');
                if (!item || !wrapper.contains(item)) {
                    return;
                }
                event.preventDefault();
                event.stopPropagation();
                toggleItemDetail(item, productMap, wrapper, detailHost);
            });
        }

        function toggleItemDetail(item, productMap, wrapper, detailHost) {
            const productId = item.getAttribute('data-value');
            if (!productId || !detailHost) {
                return;
            }

            const currentValue = detailHost.getAttribute('data-active');
            const shouldClose = currentValue === productId;

            wrapper.querySelectorAll('.lotzwoo-menu-planning__ts-item--expanded').forEach((node) => {
                node.classList.remove('lotzwoo-menu-planning__ts-item--expanded');
            });

            if (shouldClose) {
                detailHost.removeAttribute('data-active');
                detailHost.innerHTML = '';
                return;
            }

            const product = productMap.get(productId) || null;
            detailHost.innerHTML = renderDetailContent(product);
            detailHost.setAttribute('data-active', productId);

            const displayTarget = item.querySelector('.lotzwoo-menu-planning__ts-item') || item;
            displayTarget.classList.add('lotzwoo-menu-planning__ts-item--expanded');

            if (wrapper.classList.contains('input-hidden')) {
                wrapper.classList.remove('input-hidden');
            }
        }

        function renderDetailContent(product) {
            const viewLabel = config.i18n.viewProduct || 'Anzeigen';
            const editLabel = config.i18n.editProduct || 'Bearbeiten';
            const skuTemplate = config.i18n.skuLabel || 'SKU: %s';
            const skuMissing = config.i18n.skuMissing || 'unset';

            const permalink = product && product.permalink ? String(product.permalink) : '';
            const editUrl = product && product.edit_url ? String(product.edit_url) : '';
            const skuValue = product && product.sku ? String(product.sku) : '';
            const productName = product && product.name ? String(product.name) : '';

            const viewMarkup = permalink
                ? '<a href="' + escapeAttr(permalink) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(viewLabel) + '</a>'
                : '';
            const editMarkup =
                allowProductEditLinks && editUrl
                    ? '<a href="' + escapeAttr(editUrl) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(editLabel) + '</a>'
                    : '';
            const linkContent = [viewMarkup, editMarkup].filter(Boolean).join('<span class="lotzwoo-menu-planning__ts-item-link-gap">|</span>');

            const resolvedSku = skuValue ? skuTemplate.replace('%s', skuValue) : skuTemplate.replace('%s', skuMissing);
            const infoLabel = config.i18n.detailInfoLabel || 'Info';
            const titleParts = [];
            if (productName) {
                titleParts.push(escapeHtml(productName));
            }
            if (resolvedSku) {
                titleParts.push('(' + escapeHtml(resolvedSku) + ')');
            }
            const titleContent = titleParts.length ? escapeHtml(infoLabel) + ': ' + titleParts.join(' ') : '';
            const titleSection = titleContent ? '<div class="lotzwoo-menu-planning__ts-item-title">' + titleContent + '</div>' : '';
            const linksSection = linkContent ? '<div class="lotzwoo-menu-planning__ts-item-links">' + linkContent + '</div>' : '';

            return '<div class="lotzwoo-menu-planning__ts-item-detail">' + titleSection + linksSection + '</div>';
        }

        function formatStatus(entryOrStatus) {
            const status =
                typeof entryOrStatus === 'string'
                    ? entryOrStatus
                    : entryOrStatus && typeof entryOrStatus === 'object'
                    ? entryOrStatus.status
                    : '';

            const isCurrent =
                entryOrStatus && typeof entryOrStatus === 'object' && entryOrStatus.status === 'completed'
                    ? Boolean(entryOrStatus.is_current)
                    : false;

            if (status === 'completed' && isCurrent) {
                return config.i18n.statusActive || config.i18n.statusCompleted;
            }

            switch (status) {
                case 'completed':
                    return config.i18n.statusCompleted;
                case 'cancelled':
                    return config.i18n.statusCancelled;
                default:
                    return config.i18n.statusPending;
            }
        }

        function getCountdownLabel(entry) {
            if (!entry) {
                return '';
            }
            const status = entry.status;
            const now = new Date();

            if (status === 'pending') {
                const target = parseUtcDate(entry.scheduled_at_utc, entry.scheduled_at_local);
                const days = calculateDaysBetween(now, target);
                if (days === null) {
                    return '';
                }
                return formatCountdownText('pending', days);
            }

            if (status === 'completed') {
                const nextDate = getNextFutureDateAfter(entry);
                if (!nextDate) {
                    return '';
                }
                const days = calculateDaysBetween(now, nextDate);
                if (days === null) {
                    return '';
                }
                return formatCountdownText('remaining', days);
            }

            return '';
        }

        function getNextFutureDateAfter(entry) {
            const reference = parseUtcDate(entry.scheduled_at_utc, entry.scheduled_at_local) || new Date();
            let target = null;
            const now = new Date();

            state.entries.forEach((item) => {
                if (!item || item.id === entry.id) {
                    return;
                }
                if (item.status !== 'pending') {
                    return;
                }
                const date = parseUtcDate(item.scheduled_at_utc, item.scheduled_at_local);
                if (!date || date <= now || date <= reference) {
                    return;
                }
                if (!target || date < target) {
                    target = date;
                }
            });

            return target;
        }

        function parseUtcDate(utcValue, fallback) {
            let source = utcValue || '';
            if (!source && fallback) {
                source = fallback;
            }

            if (!source) {
                return null;
            }

            let normalized = source;
            if (normalized.indexOf('T') === -1) {
                normalized = normalized.replace(' ', 'T');
            }
            if (!/Z$/i.test(normalized)) {
                normalized += 'Z';
            }

            const timestamp = Date.parse(normalized);
            if (Number.isNaN(timestamp)) {
                return null;
            }
            return new Date(timestamp);
        }

        function calculateDaysBetween(fromDate, toDate) {
            if (!(fromDate instanceof Date) || !(toDate instanceof Date)) {
                return null;
            }
            const diff = toDate.getTime() - fromDate.getTime();
            if (diff < 0) {
                return null;
            }
            return Math.ceil(diff / (1000 * 60 * 60 * 24));
        }

        function formatScheduleLabel(schedule) {
            if (!schedule || typeof schedule !== 'object') {
                return '';
            }

            if (schedule.summary) {
                return String(schedule.summary);
            }

            const parts = [];
            if (schedule.frequency_display) {
                parts.push(schedule.frequency_display);
            } else if (schedule.frequency) {
                parts.push(String(schedule.frequency));
            }

            if (schedule.frequency === 'monthly') {
                parts.push(schedule.monthday_display || '');
            } else if (schedule.frequency === 'weekly') {
                parts.push(schedule.weekday_display || schedule.weekday || '');
            }

            const label = parts.filter(Boolean).join(' · ');
            const timePart = schedule.time || '';

            if (label && timePart) {
                return `${label}, ${timePart}`;
            }
            if (label) {
                return label;
            }
            return timePart;
        }

        function buildEntryTimeMarkup(entry) {
            const dateLabel = formatEntryDateLabel(entry);
            const dayTimeLabel = formatEntryDayTimeLabel(entry);
            let html = '<div>';
            if (dateLabel) {
                html += `<strong>${escapeHtml(dateLabel)}</strong>`;
            }
            if (dayTimeLabel) {
                html += '<br><strong><small>' + escapeHtml(dayTimeLabel) + '</small></strong>';
            }
            html += '</div>';
            return html;
        }

        function formatEntryDateLabel(entry) {
            if (!entry || typeof entry !== 'object') {
                return '';
            }

            if (entry.scheduled_date_display) {
                return entry.scheduled_date_display;
            }

            const parsed = parseUtcDate(entry.scheduled_at_utc, entry.scheduled_at_local);
            if (parsed instanceof Date) {
                return `${pad(parsed.getDate())}.${pad(parsed.getMonth() + 1)}.${parsed.getFullYear()}`;
            }

            const fallback = entry.scheduled_at_display || entry.scheduled_at_local || entry.scheduled_at_utc || '';
            if (!fallback) {
                return '';
            }
            const [datePart] = fallback.split(',');
            return datePart ? datePart.trim() : fallback;
        }

        function formatEntryDayTimeLabel(entry) {
            if (!entry || typeof entry !== 'object') {
                return '';
            }

            const weekday = entry.scheduled_weekday_display || entry.scheduled_weekday || '';
            const time = entry.scheduled_time_display || '';

            if (weekday && time) {
                return `${weekday}, ${time}`;
            }
            if (weekday) {
                return weekday;
            }
            if (time) {
                return time;
            }

            const parsed = parseUtcDate(entry.scheduled_at_utc, entry.scheduled_at_local);
            if (parsed instanceof Date) {
                const timePart = `${pad(parsed.getHours())}:${pad(parsed.getMinutes())}`;
                const weekdayFallback = parsed.toLocaleDateString(undefined, { weekday: 'long' });
                if (weekdayFallback && timePart) {
                    return `${weekdayFallback}, ${timePart}`;
                }
                return weekdayFallback || timePart;
            }

            const fallback = entry.scheduled_at_display || '';
            const parts = fallback.split(',');
            if (parts.length > 1) {
                return parts[1].trim();
            }
            return fallback;
        }

        function pad(value) {
            return String(value).padStart(2, '0');
        }

        function formatCountdownText(type, days) {
            if (days === null) {
                return '';
            }
            const dayLabel =
                days === 1
                    ? config.i18n.countdownDaySingle || '1 Tag'
                    : (config.i18n.countdownDayPlural || '%s Tage').replace('%s', String(days));

            if (type === 'pending') {
                return (config.i18n.countdownIn || 'In %s').replace('%s', dayLabel);
            }

            return (config.i18n.countdownRemaining || 'Noch %s').replace('%s', dayLabel);
        }

        function apiGet(action, params = {}) {
            const url = new URL(config.ajaxUrl, window.location.origin);
            url.searchParams.append('action', action);
            url.searchParams.append('nonce', config.nonce);
            Object.keys(params).forEach((key) => {
                url.searchParams.append(key, params[key]);
            });

            return fetch(url.toString(), {
                method: 'GET',
                credentials: 'same-origin',
            }).then(handleResponse);
        }

        function apiPost(action, data = {}) {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('nonce', config.nonce);
            Object.keys(data).forEach((key) => {
                formData.append(key, data[key]);
            });

            return fetch(config.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData,
            }).then(handleResponse);
        }

        function handleResponse(response) {
            if (!response.ok) {
                throw new Error(config.i18n.errorGeneric);
            }

            return response.json().then((payload) => {
                if (payload && payload.success) {
                    return payload.data || {};
                }
                const message = payload && payload.data && payload.data.message ? payload.data.message : config.i18n.errorGeneric;
                throw new Error(message);
            });
        }

        function parseJSON(value) {
            if (!value) {
                return null;
            }
            try {
                return JSON.parse(value);
            } catch (error) {
                return null;
            }
        }

        function escapeHtml(value) {
            if (typeof value !== 'string') {
                return '';
            }
            return value.replace(/[&<>"']/g, function (char) {
                const map = {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;',
                };
                return map[char] || char;
            });
        }

        function escapeAttr(value) {
            if (typeof value !== 'string') {
                return '';
            }
            return value.replace(/[&<>"']/g, function (char) {
                const map = {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;',
                };
                return map[char] || char;
            });
        }
    }
})();
