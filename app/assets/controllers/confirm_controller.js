import { Controller } from '@hotwired/stimulus';

// Demande confirmation avant d'envoyer un formulaire destructeur.
//
// La confirmation est une boite <dialog> de la page et non window.confirm() :
// un navigateur qui a vu la case « empecher cette page d'ouvrir d'autres
// boites de dialogue » cochee renvoie false sans rien afficher, et le bouton
// « Supprimer » semble alors ne rien faire. Les navigateurs integres et les
// previsualisations bloquent aussi ces boites. Le message vient de
// data-confirm-message-value ; un handler « onsubmit » en ligne serait refuse
// par la politique de contenu de l'administration.
//
// Un formulaire a plusieurs boutons ne confirme que celui qui porte son propre
// message (data-confirm-message, et data-confirm-label pour le libelle du bouton
// de la boite) : « Apercu » et « Essai » partent sans question, « Envoyer » non.
// Un bouton sans message, dans un formulaire sans message, ne demande rien.
export default class extends Controller {
    static values = { message: String, confirmLabel: { type: String, default: 'Confirmer' } };

    ask(event) {
        // Second passage : le formulaire vient d'etre renvoye apres accord.
        if (this.approved) {
            this.approved = false;

            return;
        }

        const submitter = event.submitter;
        const message = submitter?.dataset.confirmMessage || this.messageValue;
        if (!message) {
            return;
        }

        event.preventDefault();

        if (typeof HTMLDialogElement === 'undefined' || typeof HTMLDialogElement.prototype.showModal !== 'function') {
            if (window.confirm(message)) {
                this.submit(submitter);
            }

            return;
        }

        this.open(submitter, message);
    }

    submit(submitter) {
        this.approved = true;
        this.element.requestSubmit(submitter);
    }

    open(submitter, text) {
        const dialog = document.createElement('dialog');
        dialog.className = 'm-auto w-[min(28rem,calc(100%-2rem))] rounded-xl border border-slate-200 bg-white p-0 text-slate-700 shadow-xl backdrop:bg-ink/60';
        dialog.setAttribute('aria-labelledby', 'confirm-titre');
        dialog.setAttribute('aria-describedby', 'confirm-message');

        const body = document.createElement('div');
        body.className = 'p-6';

        const title = document.createElement('h2');
        title.id = 'confirm-titre';
        title.className = 'text-lg font-bold text-ink';
        title.textContent = 'Confirmer cette action';

        const message = document.createElement('p');
        message.id = 'confirm-message';
        message.className = 'mt-2';
        message.textContent = text;

        const actions = document.createElement('div');
        actions.className = 'mt-6 flex flex-wrap justify-end gap-3';

        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'btn btn-secondary';
        cancel.textContent = 'Annuler';

        const confirm = document.createElement('button');
        confirm.type = 'button';
        confirm.className = 'btn btn-danger';
        confirm.textContent = submitter?.dataset.confirmLabel || this.confirmLabelValue;

        actions.append(cancel, confirm);
        body.append(title, message, actions);
        dialog.append(body);
        document.body.append(dialog);

        const close = () => {
            dialog.close();
            dialog.remove();
        };

        cancel.addEventListener('click', close);
        // Echap ferme deja la boite : on la retire du document a la fermeture.
        dialog.addEventListener('close', () => dialog.remove());
        confirm.addEventListener('click', () => {
            close();
            this.submit(submitter);
        });

        dialog.showModal();
        // Le focus va a « Annuler » : une action destructrice ne se confirme
        // pas par un Entree distrait.
        cancel.focus();
    }
}
