import { Controller } from '@hotwired/stimulus';

// Bulletin d'adhesion : deux groupes de champs ne sont obligatoires que dans
// certains cas, que l'on rend visibles au fur et a mesure.
//
//  - « membre de votre famille qui a deja adhere » : pour la formule famille ;
//  - responsable legal (nom, prenom, e-mail) : pour un adherent de moins de 18 ans.
//
// Le champ passe en `required`, son etiquette recoit la classe `required` (qui
// affiche l'asterisque, comme les autres champs obligatoires) et la zone
// n'apparait que si elle sert : la famille pour la formule famille, le
// responsable legal pour un mineur. Tant que la date de naissance n'est pas
// saisie, l'age est inconnu : la zone reste masquee, et reparait des qu'une date
// de moins de 18 ans est donnee. Sans JavaScript, tout reste affiche et le
// serveur applique les memes regles.
export default class extends Controller {
  static targets = ['family', 'guardian'];

  connect() {
    this.refresh();
  }

  refresh() {
    const selected = this.element.querySelector('input[name$="[membershipType]"]:checked');
    const isFamily = selected?.value === 'famille';
    const familyInput = this.familyTarget.querySelector('input');

    this.familyTarget.hidden = !isFamily;
    this.setRequired(familyInput, isFamily);

    const isMinor = this.isMinor();
    // « hidden » sur un fieldset retire aussi ses champs de la navigation au
    // clavier et des lecteurs d'ecran : un adulte ne passe pas par trois champs
    // qui ne le concernent pas.
    this.guardianTarget.hidden = !isMinor;
    ['guardianLastName', 'guardianFirstName', 'guardianEmail'].forEach((field) => {
      this.setRequired(this.element.querySelector(`input[name$="[${field}]"]`), isMinor);
    });
  }

  isMinor() {
    const value = this.element.querySelector('input[name$="[birthDate]"]')?.value;
    if (!value) {
      return false;
    }

    const birth = new Date(`${value}T00:00:00`);
    if (Number.isNaN(birth.getTime())) {
      return false;
    }

    const today = new Date();
    let age = today.getFullYear() - birth.getFullYear();
    if (today.getMonth() < birth.getMonth()
      || (today.getMonth() === birth.getMonth() && today.getDate() < birth.getDate())) {
      age -= 1;
    }

    return age >= 0 && age < 18;
  }

  setRequired(input, required) {
    if (!input) {
      return;
    }

    input.required = required;
    input.setAttribute('aria-required', String(required));
    const label = this.element.querySelector(`label[for="${input.id}"]`);
    label?.classList.toggle('required', required);
  }
}
