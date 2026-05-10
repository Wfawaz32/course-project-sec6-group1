/* Populate the Course Resources list page. */
const resourceListSection = document.querySelector('#resource-list-section');

function createResourceArticle(resource) {
  const article = document.createElement('article');

  const title = document.createElement('h2');
  title.textContent = resource.title;

  const description = document.createElement('p');
  description.textContent = resource.description || '';

  const link = document.createElement('a');
  link.href = `details.html?id=${resource.id}`;
  link.textContent = 'View Resource & Discussion';

  article.append(title, description, link);
  return article;
}

async function loadResources() {
  if (!resourceListSection) return;

  const response = await fetch('./api/index.php');
  const result = await response.json();
  const resources = result && result.success && Array.isArray(result.data) ? result.data : [];

  resourceListSection.innerHTML = '';
  resources.forEach((resource) => {
    resourceListSection.appendChild(createResourceArticle(resource));
  });
}

loadResources();
