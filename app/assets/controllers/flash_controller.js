import { Controller } from '@hotwired/stimulus';

// Referme un message de confirmation. Les erreurs ne se referment pas d'elles-
// memes : on les lit, puis on les ferme.
export default class extends Controller {
    static values = { delay: Number };

    connect() {
        if (this.delayValue > 0) {
            this.timer = window.setTimeout(() => this.dismiss(), this.delayValue);
        }
    }

    disconnect() {
        window.clearTimeout(this.timer);
    }

    dismiss() {
        this.element.remove();
    }
}
