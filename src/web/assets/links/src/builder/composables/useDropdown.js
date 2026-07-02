import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Open/closed state for a single-anchor dropdown menu: document-level
 * dismissal (mousedown outside the root, Escape) and the drop-up
 * measurement that keeps a menu near the viewport bottom from stretching
 * the document. Must be called during component setup — the document
 * listeners register in onMounted / onBeforeUnmount.
 *
 * Not a fit for TokenizedInput's picker: that one dismisses against two
 * different zones (picker wrap in manual mode, whole component in trigger
 * mode) and keeps its own bespoke handler.
 */

/**
 * @param {{root: () => ?HTMLElement, onClose?: ?() => void, dropUpThreshold?: number}} config
 * `root` reads the component's outermost element (click containment +
 * drop-up measurement); `onClose` runs after every close so callers can
 * reset their menu state (clear the search query, …); `dropUpThreshold`
 * is the px of viewport below the trigger under which the menu flips up.
 */
export function useDropdown({ root, onClose = null, dropUpThreshold = 340 }) {
    const open = ref(false);
    // Opens the menu above the trigger when the viewport has more room
    // there than below — measured on every open.
    const dropUp = ref(false);

    /**
     * Flip the menu above the trigger when the space below the viewport
     * edge can't fit it and there's more room above — otherwise a menu
     * near the page bottom stretches the document and drags the CP
     * sidebar along with it.
     */
    function updateDropDirection() {
        const el = root();

        if (!el) {
            dropUp.value = false;

            return;
        }
        const rect = el.getBoundingClientRect();
        const spaceBelow = window.innerHeight - rect.bottom;
        const spaceAbove = rect.top;
        dropUp.value = spaceBelow < dropUpThreshold && spaceAbove > spaceBelow;
    }

    function openMenu() {
        updateDropDirection();
        open.value = true;
    }

    function close() {
        open.value = false;
        if (onClose) onClose();
    }

    function toggle() {
        if (open.value) {
            close();
        } else {
            openMenu();
        }
    }

    function onDocumentMousedown(e) {
        if (!open.value) return;
        const el = root();

        if (el && !el.contains(e.target)) {
            close();
        }
    }

    function onDocumentKeydown(e) {
        if (!open.value) return;
        if (e.key === 'Escape') close();
    }

    onMounted(() => {
        document.addEventListener('mousedown', onDocumentMousedown);
        document.addEventListener('keydown', onDocumentKeydown);
    });

    onBeforeUnmount(() => {
        document.removeEventListener('mousedown', onDocumentMousedown);
        document.removeEventListener('keydown', onDocumentKeydown);
    });

    return { open, dropUp, openMenu, close, toggle };
}
