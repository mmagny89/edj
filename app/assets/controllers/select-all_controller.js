import { Controller } from '@hotwired/stimulus';

// Case « tout cocher » d'une liste, et compteur de la selection. Sans
// JavaScript, chaque case reste cochable une a une et le formulaire part.
export default class extends Controller {
    static targets = ['master', 'item', 'count', 'action'];

    connect() {
        this.refresh();
    }

    toggleAll() {
        this.itemTargets.forEach((box) => {
            box.checked = this.masterTarget.checked;
        });
        this.refresh();
    }

    refresh() {
        const checked = this.itemTargets.filter((box) => box.checked).length;

        if (this.hasMasterTarget) {
            this.masterTarget.checked = checked > 0 && checked === this.itemTargets.length;
            this.masterTarget.indeterminate = checked > 0 && checked < this.itemTargets.length;
        }
        if (this.hasCountTarget) {
            this.countTarget.textContent = String(checked);
        }
        this.actionTargets.forEach((button) => {
            button.disabled = 0 === checked;
        });
    }
}
