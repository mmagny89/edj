document.addEventListener('DOMContentLoaded', () => initializeAddSlate());
document.addEventListener('turbo:load', () => initializeAddSlate());

function initializeAddSlate() {
  console.log('Initialisation de add-slate.js');
  const memberInput = document.getElementById('consumption_member');
  const suggestionsContainer = document.createElement('div');
  suggestionsContainer.id = 'member-suggestions-container';
  suggestionsContainer.className = 'hidden absolute z-10 w-full bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-md shadow-lg mt-1 max-h-60 overflow-auto';
  memberInput.parentNode.appendChild(suggestionsContainer);

  let debounceTimeout;
  memberInput.addEventListener('input', () => {
    clearTimeout(debounceTimeout);
    debounceTimeout = setTimeout(async () => {
      const query = memberInput.value.trim();
      if (query.length < 2) {
        suggestionsContainer.classList.add('hidden');
        suggestionsContainer.innerHTML = '';
        return;
      }

      try {
        const response = await fetch(`/app/members/search?query=${encodeURIComponent(query)}`, {
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        });
        if (!response.ok) {
          throw new Error(`Erreur HTTP: ${response.status}`);
        }
        const members = await response.json();
        suggestionsContainer.innerHTML = '';
        if (members.length === 0) {
          suggestionsContainer.classList.add('hidden');
          return;
        }

        members.forEach(member => {
          const div = document.createElement('div');
          div.className = 'px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer';
          div.textContent = member.memberNumber;
          div.addEventListener('click', () => {
            memberInput.value = member.memberNumber;
            suggestionsContainer.classList.add('hidden');
            suggestionsContainer.innerHTML = '';
          });
          suggestionsContainer.appendChild(div);
        });
        suggestionsContainer.classList.remove('hidden');
      } catch (error) {
        console.error('Erreur lors de la recherche de membres:', error);
        window.showToast('Erreur lors de la recherche de membres.', 'error');
      }
    }, 300);
  });

  // Cacher les suggestions si clic en dehors
  document.addEventListener('click', (e) => {
    if (!suggestionsContainer.contains(e.target) && e.target !== memberInput) {
      suggestionsContainer.classList.add('hidden');
      suggestionsContainer.innerHTML = '';
    }
  });
}
