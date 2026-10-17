import { Controller } from '@hotwired/stimulus';

// Bulletin d'adhesion : le champ « membre de votre famille qui a deja adhere »
// n'a de sens que pour la formule famille. Il apparait avec elle et disparait
// avec les autres. Sans JavaScript il reste affiche, avec sa mention.
export default class extends Controller {
  static targets = ['family'];

  connect() {
    this.refresh();
  }

  refresh() {
    const selected = this.element.querySelector('input[name$="[membershipType]"]:checked');
    const isFamily = selected?.value === 'famille';
    const input = this.familyTarget.querySelector('input');

    this.familyTarget.hidden = !isFamily;
    if (input) {
      input.required = isFamily;
    }
  }
}
