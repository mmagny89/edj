document.addEventListener('DOMContentLoaded', () => initializeModalToggles());
document.addEventListener('turbo:load', () => initializeModalToggles());

function initializeModalToggles() {
  console.log('Initialisation de modal.js');
  // Fonction pour afficher un toast
  function showToast(message, type = 'success') {
    const toastContainer = document.getElementById('toast-container');
    if (!toastContainer) {
      console.error('Conteneur #toast-container non trouvé');
      return;
    }

    const toast = document.createElement('div');
    toast.className = `px-4 py-2 rounded-lg shadow-lg text-white animate-slide-in-right ${
      type === 'success' ? 'bg-green-500' : 'bg-red-500'
    }`;
    toast.textContent = message;

    toastContainer.appendChild(toast);

    setTimeout(() => {
      toast.classList.add('animate-slide-out-right');
      setTimeout(() => toast.remove(), 300);
    }, 3000);
  }

  window.showToast = showToast; // Exposer showToast globalement

  // Supprimer les anciens écouteurs pour éviter les doublons
  function removeEventListeners(selector, eventType) {
    document.querySelectorAll(selector).forEach(element => {
      const newElement = element.cloneNode(true);
      element.parentNode.replaceChild(newElement, element);
    });
  }

  // Initialiser les modals avec data-modal-toggle
  removeEventListeners('[data-modal-toggle]', 'click');
  const modalToggles = document.querySelectorAll('[data-modal-toggle]');
  modalToggles.forEach(toggle => {
    toggle.addEventListener('click', () => {
      const modalId = toggle.getAttribute('data-modal-toggle');
      const modal = document.getElementById(modalId);
      if (modal) {
        console.log('Ouverture/fermeture de la modal:', modalId);
        modal.classList.toggle('hidden');

        // Si la modal est duplicate-game-modal, mettre à jour les formulaires
        if (modalId === 'duplicate-game-modal') {
          const gameId = toggle.getAttribute('data-game-id');
          if (!gameId) {
            console.error('data-game-id manquant sur le bouton:', toggle);
            showToast('Erreur: ID du jeu manquant.', 'error');
            return;
          }
          if (!window.csrfTokens) {
            console.error('window.csrfTokens non défini');
            showToast('Erreur: Tokens CSRF non chargés.', 'error');
            return;
          }
          const csrfToken = window.csrfTokens[gameId];
          if (!csrfToken) {
            console.error('Token CSRF manquant pour gameId:', gameId);
            showToast('Token CSRF manquant pour le jeu.', 'error');
            return;
          }
          document.querySelectorAll('.duplicate-game-form').forEach(form => {
            const baseAction = form.getAttribute('action');
            const newAction = baseAction.replace(/\/add-game\/0$/, `/add-game/${gameId}`);
            form.setAttribute('action', newAction);
            form.setAttribute('data-csrf', csrfToken);
            form.querySelector('.csrf-token').value = csrfToken;
            console.log('URL du formulaire mise à jour:', newAction);
            console.log('Token CSRF mis à jour:', csrfToken);
          });
        }

        // Réinitialiser le formulaire dans la modal, si présent
        const form = modal.querySelector('form');
        if (form && modalId !== 'duplicate-game-modal') {
          form.reset();
        }
      } else {
        console.error('Modale non trouvée pour ID:', modalId);
        showToast('Modale non trouvée.', 'error');
      }
    });
  });

  // Initialiser les boutons de fermeture avec data-modal-close
  removeEventListeners('[data-modal-close]', 'click');
  const modalCloses = document.querySelectorAll('[data-modal-close]');
  modalCloses.forEach(closeBtn => {
    closeBtn.addEventListener('click', () => {
      const modalId = closeBtn.getAttribute('data-modal-close');
      const modal = document.getElementById(modalId);
      if (modal) {
        console.log('Fermeture de la modal:', modalId);
        modal.classList.add('hidden');
      } else {
        console.error('Modale non trouvée pour ID:', modalId);
        showToast('Modale non trouvée.', 'error');
      }
    });
  });

  // Gérer la soumission des formulaires via AJAX
  removeEventListeners('[data-ajax-form]', 'submit');
  const ajaxForms = document.querySelectorAll('[data-ajax-form]');
  ajaxForms.forEach(form => {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      console.log('Soumission AJAX du formulaire:', form.id || form.className);
      const formData = new FormData(form);

      // Ajouter le token CSRF pour les formulaires spécifiques
      if (form.classList.contains('duplicate-game-form') || form.id === 'add-slate-form' || form.id === 'add-consumption-form') {
        const csrfToken = form.getAttribute('data-csrf');
        console.log('data-csrf du formulaire:', csrfToken);
        if (csrfToken) {
          formData.set('_token', csrfToken);
          console.log('Token CSRF ajouté:', csrfToken);
        } else {
          console.error('Token CSRF manquant dans data-csrf pour formulaire:', form.action);
          showToast('Token CSRF manquant.', 'error');
          return;
        }
      }

      try {
        const response = await fetch(form.action, {
          method: 'POST',
          body: formData,
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        });
        console.log('Réponse AJAX:', response.status);
        if (!response.ok) {
          throw new Error(`Erreur HTTP: ${response.status}`);
        }
        const result = await response.json();
        if (result.success) {
          if (!result.reload) {
            showToast(result.message || 'Opération réussie !', 'success');
          }
          const modal = form.closest('.modal');
          if (modal) {
            modal.classList.add('hidden');
          }
          if (result.reload) {
            window.location.reload();
          }
        } else {
          showToast(result.error || 'Une erreur est survenue.', 'error');
        }
      } catch (error) {
        console.error('Erreur lors de la soumission du formulaire:', error);
        showToast('Une erreur est survenue.', 'error');
      }
    });
  });
}
