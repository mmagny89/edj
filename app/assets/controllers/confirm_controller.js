import { Controller } from '@hotwired/stimulus';

// Demande confirmation avant d'envoyer un formulaire destructeur. Le message
// vient d'un attribut data-confirm-message-value : un handler "onsubmit" en
// ligne serait refuse par la politique de contenu de l'administration.
export default class extends Controller {
    static values = { message: String };

    ask(event) {
        if (!window.confirm(this.messageValue)) {
            event.preventDefault();
        }
    }
}
