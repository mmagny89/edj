import { Controller } from '@hotwired/stimulus';

// Infobulle des graphiques du tableau de bord.
//
// Une seule infobulle par graphique, remplie au survol ou a la prise de focus d'une
// colonne, d'une ligne de barre ou d'une zone de mois : les memes details a la
// souris et au clavier. Sans JavaScript, rien ne manque — chaque valeur est ecrite
// sur le graphique ou dans sa vue en tableau ; l'infobulle ne fait que les rassembler.
//
// Les libelles viennent de la base : ils s'inserent par textContent, jamais par
// innerHTML.
export default class extends Controller {
  static targets = ['tip', 'cross'];

  show(event) {
    const source = event.currentTarget;
    let data;
    try {
      data = JSON.parse(source.dataset.tip);
    } catch {
      return;
    }

    this.fill(data);
    this.tipTarget.hidden = false;
    this.place(source);

    // Croix des courbes : un trait vertical cale sur le mois le plus proche.
    if (this.hasCrossTarget && source.dataset.x) {
      this.crossTarget.setAttribute('x1', source.dataset.x);
      this.crossTarget.setAttribute('x2', source.dataset.x);
      this.crossTarget.classList.add('is-visible');
    }
  }

  hide() {
    this.tipTarget.hidden = true;
    if (this.hasCrossTarget) {
      this.crossTarget.classList.remove('is-visible');
    }
  }

  fill(data) {
    const tip = this.tipTarget;
    tip.replaceChildren();

    const title = document.createElement('div');
    title.className = 'viz-tip-title';
    title.textContent = data.title;
    tip.append(title);

    for (const row of data.rows ?? []) {
      const line = document.createElement('div');
      line.className = 'viz-tip-row';

      // Un trait de la couleur de la serie ; pas de pastille pleine.
      if (row.class) {
        const key = document.createElement('span');
        key.className = `viz-tip-key ${row.class}`;
        line.append(key);
      }

      const label = document.createElement('span');
      label.className = 'viz-tip-label';
      label.textContent = row.label;

      const value = document.createElement('span');
      value.className = 'viz-tip-value';
      value.textContent = String(row.value);

      line.append(label, value);
      tip.append(line);
    }
  }

  // Au-dessus de la cible, centree, sans sortir du graphique ; en dessous si le
  // haut manque de place.
  place(source) {
    const host = this.element.getBoundingClientRect();
    const target = source.getBoundingClientRect();
    const tip = this.tipTarget;

    const left = target.left - host.left + target.width / 2 - tip.offsetWidth / 2;
    const maxLeft = Math.max(0, host.width - tip.offsetWidth);
    let top = target.top - host.top - tip.offsetHeight - 8;
    if (top < 0) {
      top = target.bottom - host.top + 8;
    }

    tip.style.left = `${Math.min(Math.max(0, left), maxLeft)}px`;
    tip.style.top = `${top}px`;
  }
}
