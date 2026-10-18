import { Controller } from '@hotwired/stimulus';

// Oeil d'affichage d'un champ mot de passe. Le bouton est cache dans le HTML et
// revele ici : sans JavaScript, le champ reste un champ mot de passe ordinaire
// plutot qu'un bouton qui ne fait rien.
//
// Bouton a deux etats au sens ARIA (aria-pressed) : son nom accessible ne
// change donc pas d'un etat a l'autre, c'est l'etat enfonce qui porte
// l'information.
export default class extends Controller {
    static targets = ['input', 'button', 'show', 'hide'];

    connect() {
        this.buttonTarget.hidden = false;
        this.render(false);
    }

    // Le champ redevient masque des que la page est quittee ou le formulaire
    // envoye : un mot de passe laisse en clair reste lisible a l'ecran.
    disconnect() {
        this.render(false);
    }

    toggle() {
        this.render('password' === this.inputTarget.type);
    }

    render(visible) {
        this.inputTarget.type = visible ? 'text' : 'password';
        this.buttonTarget.setAttribute('aria-pressed', visible ? 'true' : 'false');
        this.showTarget.hidden = visible;
        this.hideTarget.hidden = !visible;
    }
}
