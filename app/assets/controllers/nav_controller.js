import { Controller } from '@hotwired/stimulus';

// Menu replie sur petit ecran. Le bouton est cache dans le HTML et revele ici :
// sans JavaScript, le menu reste deplie et tout reste accessible.
export default class extends Controller {
    static targets = ['button', 'panel'];

    connect() {
        this.buttonTarget.hidden = false;
        this.close();
        this.onKey = (event) => {
            if ('Escape' === event.key && this.isOpen()) {
                this.close();
                this.buttonTarget.focus();
            }
        };
        document.addEventListener('keydown', this.onKey);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKey);
    }

    toggle() {
        this.isOpen() ? this.close() : this.open();
    }

    open() {
        this.panelTarget.classList.remove('hidden');
        this.buttonTarget.setAttribute('aria-expanded', 'true');
    }

    close() {
        this.panelTarget.classList.add('hidden');
        this.buttonTarget.setAttribute('aria-expanded', 'false');
    }

    isOpen() {
        return 'true' === this.buttonTarget.getAttribute('aria-expanded');
    }
}
