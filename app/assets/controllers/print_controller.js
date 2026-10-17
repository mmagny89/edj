import { Controller } from '@hotwired/stimulus';

// Ouvre la boite d'impression du navigateur. Un lien « javascript:print() » ou
// un onclick en ligne serait refuse par la politique de contenu.
export default class extends Controller {
    now() {
        window.print();
    }
}
