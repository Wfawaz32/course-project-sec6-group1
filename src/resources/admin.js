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

  const titleCell = document.createElement('td');
  titleCell.textContent = resource.title;

  const descriptionCell = document.createElement('td');
  descriptionCell.textContent = resource.description || '';

  const linkCell = document.createElement('td');
  linkCell.textContent = resource.link;

  const actionsCell = document.createElement('td');
  const editButton = document.createElement('button');
  editButton.textContent = 'Edit';
  editButton.className = 'edit-btn';
  editButton.dataset.id = resource.id;

  const deleteButton = document.createElement('button');
  deleteButton.textContent = 'Delete';
  deleteButton.className = 'delete-btn';
  deleteButton.dataset.id = resource.id;

  actionsCell.append(editButton, deleteButton);
  row.append(titleCell, descriptionCell, linkCell, actionsCell);
  return row;
}

function renderTable() {
  resourcesTbody.innerHTML = '';
  resources.forEach((resource) => {
    resourcesTbody.appendChild(createResourceRow(resource));
  });
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
    return;
  }

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

async function handleTableClick(event) {
  const target = event.target;
  const id = target.dataset ? target.dataset.id : null;

  if (target.classList.contains('delete-btn')) {
    const response = await fetch(`./api/index.php?id=${id}`, { method: 'DELETE' });
    const result = await response.json();

    if (result.success) {
      resources = resources.filter((resource) => String(resource.id) !== String(id));
      renderTable();
    }
  }

  if (target.classList.contains('edit-btn')) {
    const resource = resources.find((item) => String(item.id) === String(id));
    if (!resource) return;

    editingResourceId = id;
    titleInput.value = resource.title;
    descriptionInput.value = resource.description || '';
    linkInput.value = resource.link;
    submitButton.textContent = 'Update Resource';
  }
}

async function loadAndInitialize() {
  const response = await fetch('./api/index.php');
  const result = await response.json();
  resources = result && result.success && Array.isArray(result.data) ? result.data : [];
  renderTable();

  if (!loadAndInitialize._listenersAttached) {
    resourceForm.addEventListener('submit', handleAddResource);
    resourcesTbody.addEventListener('click', handleTableClick);
    loadAndInitialize._listenersAttached = true;
  }
}

loadAndInitialize();
