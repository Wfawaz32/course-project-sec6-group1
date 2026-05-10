/* Make the Manage Resources page interactive. */
let resources = [];
let editingResourceId = null;

const resourceForm = document.querySelector('#resource-form');
const resourcesTbody = document.querySelector('#resources-tbody');
const titleInput = document.querySelector('#resource-title');
const descriptionInput = document.querySelector('#resource-description');
const linkInput = document.querySelector('#resource-link');
const submitButton = document.querySelector('#add-resource');

function createResourceRow(resource) {
  const row = document.createElement('tr');
  row.innerHTML = `
    <td>${resource.title}</td>
    <td>${resource.description || ''}</td>
    <td>${resource.link}</td>
    <td>
      <button class="edit-btn" data-id="${resource.id}">Edit</button>
      <button class="delete-btn" data-id="${resource.id}">Delete</button>
    </td>
  `;
  return row;
}

function renderTable() {
  if (resourcesTbody) {
    resourcesTbody.innerHTML = '';
    resources.forEach((resource) => {
      resourcesTbody.appendChild(createResourceRow(resource));
    });
  }
}

async function handleAddResource(event) {
  event.preventDefault();

  const title = titleInput.value.trim();
  const description = descriptionInput.value.trim();
  const link = linkInput.value.trim();

  if (editingResourceId !== null) {
    const response = await fetch('./api/index.php', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: editingResourceId, title, description, link }),
    });
    const result = await response.json();

    if (result.success) {
      resources = resources.map((resource) => (
        String(resource.id) === String(editingResourceId)
          ? { ...resource, title, description, link }
          : resource
      ));
      editingResourceId = null;
      submitButton.textContent = 'Add Resource';
      resourceForm.reset();
      renderTable();
    }
  } else {
    const response = await fetch('./api/index.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ title, description, link }),
    });
    const result = await response.json();

    if (result.success) {
      resources.push({ id: result.id, title, description, link });
      renderTable();
      resourceForm.reset();
    }
  }
}

async function handleTableClick(event) {
  const target = event.target;
  const id = target.dataset.id;
  
  if (!id) return;

  if (target.classList.contains('delete-btn')) {
    const response = await fetch(`./api/index.php?id=${id}`, { method: 'DELETE' });
    const result = await response.json();

    if (result.success) {
      resources = resources.filter((resource) => String(resource.id) !== String(id));
      renderTable();
    }
  } else if (target.classList.contains('edit-btn')) {
    const resource = resources.find((item) => String(item.id) === String(id));
    if (resource) {
      editingResourceId = id;
      titleInput.value = resource.title;
      descriptionInput.value = resource.description || '';
      linkInput.value = resource.link;
      submitButton.textContent = 'Update Resource';
    }
  }
}

async function loadAndInitialize() {
  if (resourceForm) {
    resourceForm.addEventListener('submit', handleAddResource);
  }
  if (resourcesTbody) {
    resourcesTbody.addEventListener('click', handleTableClick);
  }

  try {
    const response = await fetch('./api/index.php');
    const result = await response.json();
    resources = result && result.success && Array.isArray(result.data) ? result.data : [];
    renderTable();
  } catch (error) {
    console.error("Initialization failed:", error);
  }
}

// Ensure the code runs after DOM is loaded
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loadAndInitialize);
} else {
    loadAndInitialize();
}

// Export for Autograder testing
window.renderTable = renderTable;
window.resources = resources;