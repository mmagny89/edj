import { Controller } from '@hotwired/stimulus';

// Editeur de texte enrichi tres simple pour les messages aux adherents : gras,
// italique, souligne, lien, alignement, liste, titre. Sans bibliotheque (pas de
// CDN) : une zone contenteditable et document.execCommand, depreciee mais
// toujours prise en charge par tous les navigateurs et sans equivalent natif.
//
// Le champ <textarea> reste la source envoyee au serveur, qui nettoie de toute
// facon le HTML (MailingTemplate::normalize) : l'editeur n'est qu'un confort, rien
// de ce qu'il produit n'est cru. Sans JavaScript, le textarea reste affiche et
// accepte du texte simple. Le lien se saisit dans un champ de la barre d'outils
// et non dans window.prompt(), qu'un navigateur peut bloquer sans rien afficher.
export default class extends Controller {
    static targets = ['source'];

    connect() {
        this.source = this.sourceTarget;
        this.buildToolbar();
        this.editor = document.createElement('div');
        this.editor.className = 'richtext__editor';
        this.editor.contentEditable = 'true';
        this.editor.setAttribute('role', 'textbox');
        this.editor.setAttribute('aria-multiline', 'true');
        this.editor.setAttribute('aria-label', 'Message');
        this.editor.innerHTML = this.initialHtml();
        this.editor.addEventListener('input', () => this.sync());
        this.editor.addEventListener('blur', () => this.sync());
        // Coller sans la mise en forme d'un traitement de texte ou d'une page web.
        this.editor.addEventListener('paste', (event) => {
            event.preventDefault();
            const text = event.clipboardData.getData('text/plain');
            document.execCommand('insertText', false, text);
        });

        this.source.hidden = true;
        this.source.insertAdjacentElement('beforebegin', this.toolbar);
        this.source.insertAdjacentElement('beforebegin', this.editor);
        this.source.form?.addEventListener('submit', () => this.sync());
        this.sync();
    }

    disconnect() {
        this.toolbar?.remove();
        this.editor?.remove();
        this.source.hidden = false;
    }

    // Un texte simple (sans balise) devient des paragraphes ; du HTML est repris tel quel.
    initialHtml() {
        const value = this.source.value.trim();
        if (value === '' || /<\/?[a-z][^>]*>/i.test(value)) {
            return value;
        }

        const escape = (text) => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

        return value
            .split(/\n{2,}/)
            .map((paragraph) => `<p>${escape(paragraph).replace(/\n/g, '<br>')}</p>`)
            .join('');
    }

    buildToolbar() {
        const buttons = [
            ['bold', 'G', 'Gras', 'richtext__bold'],
            ['italic', 'I', 'Italique', 'richtext__italic'],
            ['underline', 'S', 'Souligné', 'richtext__underline'],
            ['|'],
            ['justifyLeft', '⇤', 'Aligner à gauche'],
            ['justifyCenter', '↔', 'Centrer'],
            ['|'],
            ['insertUnorderedList', '•', 'Liste à puces'],
            ['heading', 'T', 'Titre'],
            ['|'],
            ['link', 'Lien', 'Ajouter un lien'],
            ['unlink', 'Sans lien', 'Retirer le lien'],
            ['removeFormat', 'Aa', 'Effacer la mise en forme'],
        ];

        this.toolbar = document.createElement('div');
        this.toolbar.className = 'richtext__toolbar';
        this.toolbar.setAttribute('role', 'toolbar');
        this.toolbar.setAttribute('aria-label', 'Mise en forme du message');

        buttons.forEach(([command, label, title, extra]) => {
            if (command === '|') {
                const separator = document.createElement('span');
                separator.className = 'richtext__separator';
                separator.setAttribute('aria-hidden', 'true');
                this.toolbar.append(separator);

                return;
            }

            const button = document.createElement('button');
            button.type = 'button';
            button.className = `richtext__button ${extra ?? ''}`.trim();
            button.textContent = label;
            button.title = title;
            button.setAttribute('aria-label', title);
            // mousedown + preventDefault : le clic ne doit pas enlever la selection de l'editeur.
            button.addEventListener('mousedown', (event) => event.preventDefault());
            button.addEventListener('click', () => this.run(command));
            this.toolbar.append(button);
        });

        this.linkRow = document.createElement('div');
        this.linkRow.className = 'richtext__link';
        this.linkRow.hidden = true;
        this.linkInput = document.createElement('input');
        this.linkInput.type = 'url';
        this.linkInput.placeholder = 'https://…';
        this.linkInput.setAttribute('aria-label', "Adresse du lien (commence par https://)");
        this.linkInput.className = 'richtext__link-input';
        const apply = document.createElement('button');
        apply.type = 'button';
        apply.className = 'richtext__button';
        apply.textContent = 'Valider';
        apply.addEventListener('click', () => this.applyLink());
        this.linkInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                this.applyLink();
            } else if (event.key === 'Escape') {
                this.closeLink();
            }
        });
        this.linkRow.append(this.linkInput, apply);
        this.toolbar.append(this.linkRow);
    }

    run(command) {
        this.editor.focus();
        if (command === 'link') {
            this.saveSelection();
            this.linkRow.hidden = false;
            this.linkInput.focus();

            return;
        }

        if (command === 'heading') {
            // Bascule titre / paragraphe.
            const current = document.queryCommandValue('formatBlock').toLowerCase();
            document.execCommand('formatBlock', false, current === 'h2' ? 'p' : 'h2');
        } else {
            document.execCommand(command, false, null);
        }
        this.sync();
    }

    saveSelection() {
        const selection = window.getSelection();
        this.range = selection.rangeCount > 0 ? selection.getRangeAt(0).cloneRange() : null;
    }

    applyLink() {
        const url = this.linkInput.value.trim();
        // http(s) et mailto seulement, comme le serveur : un autre schema serait retire de toute facon.
        if (!/^(https?:\/\/|mailto:)/i.test(url)) {
            this.linkInput.setCustomValidity("L'adresse doit commencer par https:// ou http://");
            this.linkInput.reportValidity();

            return;
        }

        this.linkInput.setCustomValidity('');
        this.editor.focus();
        if (this.range) {
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(this.range);
        }
        // Sans texte selectionne, l'adresse sert aussi de texte au lien.
        if (window.getSelection().isCollapsed) {
            document.execCommand('insertHTML', false, `<a href="${url.replace(/"/g, '&quot;')}">${url.replace(/&/g, '&amp;').replace(/</g, '&lt;')}</a>`);
        } else {
            document.execCommand('createLink', false, url);
        }
        this.closeLink();
        this.sync();
    }

    closeLink() {
        this.linkInput.value = '';
        this.linkRow.hidden = true;
        this.editor.focus();
    }

    sync() {
        const html = this.editor.innerHTML;
        // Un editeur vide laisse parfois <br> ou <p><br></p> : le champ reste vide.
        this.source.value = this.editor.textContent.trim() === '' && !this.editor.querySelector('a') ? '' : html;
    }
}
