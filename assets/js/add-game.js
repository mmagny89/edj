document.addEventListener('DOMContentLoaded', () => initializeModalToggles());
document.addEventListener('turbo:load', () => initializeModalToggles());

function initializeModalToggles() {
  // Fonction de debouncing
  function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
      const later = () => {
        clearTimeout(timeout);
        func(...args);
      };
      clearTimeout(timeout);
      timeout = setTimeout(later, wait);
    };
  }

  // Fonction pour afficher un toast
  function showToast(message, type = 'success') {
    const toastContainer = document.getElementById('toast-container');
    if (!toastContainer) return;

    const toast = document.createElement('div');
    toast.className = `px-4 py-2 rounded-lg shadow-lg text-white animate-slide-in-right ${
      type === 'success' ? 'bg-green-500' : 'bg-red-500'
    }`;
    toast.textContent = message;

    toastContainer.appendChild(toast);

    // Auto-fermeture après 3 secondes
    setTimeout(() => {
      toast.classList.add('animate-slide-out-right');
      setTimeout(() => toast.remove(), 300);
    }, 3000);
  }

  // Fonction pour initialiser l'autocomplétion dans la modale d'ajout
  function initializeAddModal(modal) {
    console.log('Initialisation de la modale d\'ajout');
    const gameInput = document.getElementById('add_game_name');
    if (!gameInput) {
      console.error('Champ gameInput non trouvé dans le DOM');
      return;
    }

    // Réinitialiser le formulaire
    const form = modal.querySelector('#add-game-form');
    if (form) {
      form.reset();
    }

    const suggestionsContainer = document.getElementById('suggestions-container');
    const searchLoader = document.getElementById('search-loader');
    const detailsLoader = document.getElementById('details-loader');

    const searchGames = debounce(async (query) => {
      if (query.length < 3) {
        suggestionsContainer.className = suggestionsContainer.className.replace('block', 'hidden');
        suggestionsContainer.innerHTML = '';
        if (searchLoader) {
          searchLoader.className = searchLoader.className.replace('block', 'hidden');
        }
        return;
      }

      try {
        if (searchLoader) {
          searchLoader.className = searchLoader.className.replace('hidden', 'block');
        }
        const response = await fetch(`/app/games/search?query=${encodeURIComponent(query)}`, {
          headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          }
        });
        if (!response.ok) {
          throw new Error(`Erreur HTTP: ${response.status}`);
        }
        const games = await response.json();

        suggestionsContainer.innerHTML = '';
        if (games.length > 0) {
          suggestionsContainer.className = suggestionsContainer.className.replace('hidden', 'block');
          games.forEach(game => {
            const div = document.createElement('div');
            div.className = 'px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer';
            div.textContent = game.name + (game.year ? ` (${game.year})` : '');
            div.addEventListener('click', async () => {
              gameInput.value = game.name;
              document.getElementById('add_game_bggId').value = game.id;

              try {
                if (detailsLoader) {
                  detailsLoader.className = detailsLoader.className.replace('hidden', 'block');
                }
                const detailsResponse = await fetch(`/app/games/${game.id}/details`, {
                  headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                  }
                });
                if (!detailsResponse.ok) {
                  throw new Error(`Erreur HTTP: ${detailsResponse.status}`);
                }
                const details = await detailsResponse.json();
                document.getElementById('add_game_imageUrl').value = details.image || '';
              } catch (error) {
                console.error('Erreur lors de la récupération des détails du jeu :', error);
                document.getElementById('add_game_imageUrl').value = '';
                showToast('Erreur lors de la récupération des détails du jeu.', 'error');
              } finally {
                if (detailsLoader) {
                  detailsLoader.className = detailsLoader.className.replace('block', 'hidden');
                }
              }

              suggestionsContainer.className = suggestionsContainer.className.replace('block', 'hidden');
              suggestionsContainer.innerHTML = '';
            });
            suggestionsContainer.appendChild(div);
          });
        } else {
          suggestionsContainer.className = suggestionsContainer.className.replace('block', 'hidden');
        }
      } catch (error) {
        console.error('Erreur lors de la recherche de jeux :', error);
        suggestionsContainer.className = suggestionsContainer.className.replace('block', 'hidden');
        suggestionsContainer.innerHTML = '';
        showToast('Erreur lors de la recherche de jeux.', 'error');
      } finally {
        if (searchLoader) {
          searchLoader.className = searchLoader.className.replace('block', 'hidden');
        }
      }
    }, 300);

    gameInput.addEventListener('input', () => {
      const query = gameInput.value;
      searchGames(query);
    });

    document.addEventListener('click', (e) => {
      if (!gameInput.contains(e.target) && !suggestionsContainer.contains(e.target)) {
        suggestionsContainer.className = suggestionsContainer.className.replace('block', 'hidden');
        suggestionsContainer.innerHTML = '';
      }
    });
  }

  // Nettoyer les anciens écouteurs pour éviter les doublons
  function removeEventListeners(selector, eventType, handler) {
    document.querySelectorAll(selector).forEach(element => {
      const newElement = element.cloneNode(true);
      element.parentNode.replaceChild(newElement, element);
    });
  }

  // Fonction pour ouvrir le modal de duplication
  window.openDuplicateModal = function(gameId) {
    const modal = document.getElementById('duplicate-game-modal');
    if (!modal) {
      console.error('Modal de duplication non trouvé');
      showToast('Modal de duplication non trouvé.', 'error');
      return;
    }

    // Met à jour les formulaires avec le bon gameId et CSRF token
    document.querySelectorAll('.duplicate-game-form').forEach(form => {
      const eventId = form.getAttribute('data-event-id');
      form.action = `/app/events/${eventId}/add-game/${gameId}`;
      const csrfInput = form.querySelector('.csrf-token');
      if (csrfInput && window.csrfTokens && window.csrfTokens[gameId]) {
        csrfInput.value = window.csrfTokens[gameId];
      } else {
        console.warn('Token CSRF non trouvé pour gameId:', gameId);
      }
    });

    modal.classList.remove('hidden');
    console.log('Modal de duplication ouvert pour gameId:', gameId);
  };

  // Fonction pour fermer le modal de duplication
  window.closeDuplicateModal = function() {
    const modal = document.getElementById('duplicate-game-modal');
    if (modal) {
      modal.classList.add('hidden');
    }
  };

  // Supprimer les anciens écouteurs pour éviter les doublons
  removeEventListeners('[data-modal-toggle]', 'click');
  removeEventListeners('.remove-game-form', 'submit');
  removeEventListeners('.duplicate-game-form', 'submit');

  // Ajouter les écouteurs pour les modals
  const modalToggles = document.querySelectorAll('[data-modal-toggle]');
  modalToggles.forEach(toggle => {
    toggle.addEventListener('click', () => {
      const modalId = toggle.getAttribute('data-modal-toggle');
      const modal = document.getElementById(modalId);
      if (modal) {
        modal.classList.toggle('hidden');
        if (!modal.classList.contains('hidden') && modalId === 'add-game-modal') {
          initializeAddModal(modal);
        }
      } else {
        console.error('Modale non trouvée pour ID :', modalId);
        showToast('Modale non trouvée.', 'error');
      }
    });
  });

  // Gérer la suppression des jeux via AJAX
  const deleteForms = document.querySelectorAll('.remove-game-form');
  deleteForms.forEach(form => {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const formData = new FormData(form);
      console.log('Soumission du formulaire de suppression:', form.action);
      try {
        const response = await fetch(form.action, {
          method: 'POST',
          body: formData,
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        });
        console.log('Réponse reçue pour suppression:', response.status);
        if (!response.ok) {
          throw new Error(`Erreur HTTP: ${response.status}`);
        }
        const result = await response.json();
        if (result.success) {
          form.closest('article').remove();
          showToast('Jeu supprimé avec succès !', 'success');
        } else {
          showToast(result.error || 'Erreur lors de la suppression du jeu.', 'error');
        }
      } catch (error) {
        console.error('Erreur lors de la suppression du jeu :', error);
        showToast('Erreur lors de la suppression du jeu.', 'error');
      }
    });
  });

  // Gérer les soumissions du modal de duplication via AJAX
  const duplicateForms = document.querySelectorAll('.duplicate-game-form');
  duplicateForms.forEach(form => {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const formData = new FormData(form);
      console.log('Soumission du formulaire pour duplication:', form.action);
      try {
        const response = await fetch(form.action, {
          method: 'POST',
          body: formData,
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        });
        console.log('Réponse reçue pour duplication:', response.status);
        if (!response.ok) {
          throw new Error(`Erreur HTTP: ${response.status}`);
        }
        const result = await response.json();
        if (result.success) {
          showToast('Jeu ajouté à l\'événement !', 'success');
          closeDuplicateModal();
        } else {
          showToast(result.error || 'Erreur lors de l\'ajout du jeu.', 'error');
        }
      } catch (error) {
        console.error('Erreur lors de la duplication du jeu :', error);
        showToast('Une erreur est survenue lors de l\'ajout du jeu.', 'error');
      }
    });
  });
}
