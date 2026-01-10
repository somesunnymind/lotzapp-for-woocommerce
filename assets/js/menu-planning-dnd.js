(function () {
    'use strict';

    if (typeof document === 'undefined') {
        return;
    }

    function deriveCategorySlug(tagSlug) {
        if (!tagSlug) {
            return '';
        }
        let slug = String(tagSlug);
        const prefix = 'currentmenu_';
        if (slug.indexOf(prefix) === 0) {
            slug = slug.slice(prefix.length);
        }
        if (!slug) {
            return '';
        }
        const parts = slug.split('_');
        return parts[0] || '';
    }

    class LotzwooMenuPlanningDnD {
        constructor() {
            this.instances = new Map();
            this.observers = new Map();
            this.controlsWithHandlers = new WeakSet();
            this.currentDropControl = null;
            this.pointerState = null;
            this.idCounter = 0;

            this.onPointerMove = (event) => this.handlePointerMove(event);
            this.onPointerUp = (event) => this.handlePointerUp(event);
            this.onPointerCancel = (event) => this.handlePointerCancel(event);

            document.addEventListener('lotzwoo:tomselect-init', (event) => this.handleInit(event));
            setTimeout(() => this.bootstrapExisting(), 0);
        }

        bootstrapExisting() {
            document.querySelectorAll('select[data-tag-slug]').forEach((select) => {
                if (!select.tomselect) {
                    return;
                }
                this.handleInit({
                    detail: {
                        select,
                        instance: select.tomselect,
                        tagSlug: select.dataset.tagSlug || '',
                        tagName: select.dataset.tagName || '',
                        categorySlug: select.dataset.categorySlug || '',
                    },
                });
            });
        }

        handleInit(event) {
            const detail = event && event.detail ? event.detail : null;
            if (!detail || !detail.instance || !detail.select) {
                return;
            }

            const slug = detail.tagSlug || detail.select.dataset.tagSlug || '';
            if (!slug) {
                return;
            }
            const categorySlug =
                detail.categorySlug || detail.select.dataset.categorySlug || deriveCategorySlug(slug);

            const wrapper = detail.instance.wrapper;
            const control = detail.instance.control;
            if (!wrapper || !control) {
                return;
            }

            let id = wrapper.dataset.lotzwooSelectId;
            if (!id) {
                id = 'lotzwoo-ts-' + ++this.idCounter;
            }
            wrapper.dataset.lotzwooSelectId = id;
            wrapper.dataset.lotzwooColumn = slug;
            wrapper.dataset.lotzwooCategory = categorySlug;
            control.dataset.lotzwooSelectId = id;
            control.dataset.lotzwooColumn = slug;
            control.dataset.lotzwooCategory = categorySlug;

            this.disconnectObserver(id);
            this.instances.set(id, {
                id,
                instance: detail.instance,
                select: detail.select,
                slug,
                categoryKey: categorySlug,
                wrapper,
                control,
            });

            this.observeWrapper(id, control);
            this.attachPointerHandlers(control);
        }

        observeWrapper(id, control) {
            const observer = new MutationObserver(() => this.attachPointerHandlers(control));
            observer.observe(control, { childList: true, subtree: true });
            this.observers.set(id, observer);
        }

        disconnectObserver(id) {
            const observer = this.observers.get(id);
            if (observer) {
                observer.disconnect();
                this.observers.delete(id);
            }
        }

        attachPointerHandlers(control) {
            if (!control || this.controlsWithHandlers.has(control)) {
                return;
            }
            control.addEventListener('pointerdown', (event) => this.handlePointerDown(event), true);
            this.controlsWithHandlers.add(control);
        }

        handlePointerDown(event) {
            if (event.button !== 0) {
                return;
            }
            if (event.target && event.target.closest('.remove')) {
                return;
            }
            const item = event.target && event.target.closest('.ts-wrapper .item');
            if (!item) {
                return;
            }
            const control = item.closest('.ts-control');
            const entry = this.getInstanceByControl(control);
            if (!entry || this.isDisabled(entry)) {
                return;
            }
            const value = item.getAttribute('data-value');
            if (!value) {
                return;
            }

            this.pointerState = {
                pointerId: event.pointerId,
                startX: event.clientX,
                startY: event.clientY,
                started: false,
                item,
                entry,
                value,
                slug: entry.slug,
                categoryKey: entry.categoryKey || '',
                ghost: null,
            };

            try {
                item.setPointerCapture(event.pointerId);
            } catch (error) {
                // ignore
            }

            document.addEventListener('pointermove', this.onPointerMove, true);
            document.addEventListener('pointerup', this.onPointerUp, true);
            document.addEventListener('pointercancel', this.onPointerCancel, true);
        }

        handlePointerMove(event) {
            const state = this.pointerState;
            if (!state || event.pointerId !== state.pointerId) {
                return;
            }

            const deltaX = Math.abs(event.clientX - state.startX);
            const deltaY = Math.abs(event.clientY - state.startY);

            if (!state.started) {
                if (deltaX < 4 && deltaY < 4) {
                    return;
                }
                this.beginDrag(event);
            }

            event.preventDefault();
            this.updateGhostPosition(event);
            this.updateDropTarget(event);
        }

        handlePointerUp(event) {
            const state = this.pointerState;
            if (!state || event.pointerId !== state.pointerId) {
                return;
            }

            if (state.started && this.currentDropControl) {
                const target = this.getInstanceByControl(this.currentDropControl);
                if (this.canDropOn(state, target)) {
                    this.performMove(state, target);
                }
            }
            this.resetPointerState();
        }

        handlePointerCancel(event) {
            const state = this.pointerState;
            if (!state || event.pointerId !== state.pointerId) {
                return;
            }
            this.resetPointerState();
        }

        beginDrag(event) {
            const state = this.pointerState;
            if (!state) {
                return;
            }
            state.started = true;
            state.item.classList.add('lotzwoo-ts-dragging');
            state.ghost = this.createGhost(state.item);
            this.updateGhostPosition(event);
        }

        updateGhostPosition(event) {
            const state = this.pointerState;
            if (!state || !state.ghost) {
                return;
            }
            state.ghost.style.left = event.clientX + 12 + 'px';
            state.ghost.style.top = event.clientY + 12 + 'px';
        }

        updateDropTarget(event) {
            const element = document.elementFromPoint(event.clientX, event.clientY);
            const hoveredControl = element ? element.closest('.ts-control') : null;
            this.refreshCandidateHighlight();
            const target = this.getInstanceByControl(hoveredControl);
            if (hoveredControl && this.pointerState && this.canDropOn(this.pointerState, target)) {
                this.setDropTarget(hoveredControl);
            } else {
                this.clearDropTarget();
            }
        }

        canDropOn(state, target) {
            if (!state || !target) {
                return false;
            }
            if (this.isDisabled(target)) {
                return false;
            }
            const sourceKey = state.categoryKey || state.slug;
            const targetKey = target.categoryKey || target.slug;
            if (sourceKey !== targetKey) {
                return false;
            }
            if (target.id === state.entry.id) {
                return false;
            }
            if (this.hasValue(target, state.value)) {
                return false;
            }
            return true;
        }

        performMove(state, target) {
            if (!state || !target) {
                return;
            }

            try {
                target.instance.addItem(state.value);
                if (!this.hasValue(target, state.value)) {
                    return;
                }
                if (this.hasValue(state.entry, state.value)) {
                    state.entry.instance.removeItem(state.value);
                }
            } catch (error) {
                console.error('[lotzwoo] Drag & Drop failed', error);
            }
        }

        hasValue(entry, value) {
            if (!entry || !entry.instance) {
                return false;
            }
            const items = Array.isArray(entry.instance.items) ? entry.instance.items : [];
            return items.includes(value);
        }

        isDisabled(entry) {
            if (!entry || !entry.instance) {
                return true;
            }
            if (entry.instance.isDisabled) {
                return true;
            }
            return entry.control.classList.contains('disabled');
        }

        getInstanceByControl(control) {
            if (!control) {
                return null;
            }
            const wrapper = control.closest('.ts-wrapper');
            if (!wrapper) {
                return null;
            }
            const id = wrapper.dataset.lotzwooSelectId;
            return id ? this.instances.get(id) : null;
        }

        setDropTarget(control) {
            if (this.currentDropControl === control) {
                return;
            }
            this.clearDropTarget();
            this.currentDropControl = control;
            control.classList.add('lotzwoo-ts-drop-allowed');
        }

        clearDropTarget() {
            if (this.currentDropControl) {
                this.currentDropControl.classList.remove('lotzwoo-ts-drop-allowed');
                this.currentDropControl = null;
            }
        }

        resetPointerState() {
            const state = this.pointerState;
            if (state) {
                if (state.item) {
                    state.item.classList.remove('lotzwoo-ts-dragging');
                }
                if (state.ghost && state.ghost.parentNode) {
                    state.ghost.parentNode.removeChild(state.ghost);
                }
                try {
                    state.item.releasePointerCapture(state.pointerId);
                } catch (error) {
                    // ignore
                }
            }

            document.removeEventListener('pointermove', this.onPointerMove, true);
            document.removeEventListener('pointerup', this.onPointerUp, true);
            document.removeEventListener('pointercancel', this.onPointerCancel, true);

            this.clearDropTarget();
            this.pointerState = null;
            this.clearCandidateHighlight();
        }

        createGhost(item) {
            const ghost = item.cloneNode(true);
            ghost.classList.add('lotzwoo-ts-ghost');
            ghost.style.position = 'fixed';
            ghost.style.pointerEvents = 'none';
            ghost.style.zIndex = '9999';
            document.body.appendChild(ghost);
            return ghost;
        }

        refreshCandidateHighlight() {
            this.clearCandidateHighlight();
            const state = this.pointerState;
            if (!state) {
                return;
            }
            this.instances.forEach((entry) => {
                if (this.canDropOn(state, entry)) {
                    entry.control.classList.add('lotzwoo-ts-drop-candidate');
                }
            });
        }

        clearCandidateHighlight() {
            this.instances.forEach((entry) => {
                entry.control.classList.remove('lotzwoo-ts-drop-candidate');
            });
        }
    }

    new LotzwooMenuPlanningDnD();
})();
