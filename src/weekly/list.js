/*
  Requirement: Populate the "Weekly Course Breakdown" list page.

  Instructions:
  1. This file is already linked to `list.html` via:
         <script src="list.js" defer></script>

  2. In `list.html`, the <section id="week-list-section"> is the container
     that this script populates.

  3. Implement the TODOs below.
*/

// --- Element Selections ---
// Select the section for the week list using its id 'week-list-section'.
const weekListSection = document.getElementById('week-list-section');

// --- Functions ---

/**
 * Implement createWeekArticle.
 *
 * Parameters:
 *   week — one object from the API response with the shape:
 *     {
 *       id:          number,   // integer primary key from the weeks table
 *       title:       string,
 *       start_date:  string,   // "YYYY-MM-DD" — matches the SQL column name
 *       description: string,
 *       links:       string[]  // already decoded array of URL strings
 *     }
 *
 * Returns:
 *   An <article> element matching the structure shown in list.html:
 *     <article>
 *       <h2>{title}</h2>
 *       <p>Starts on: {start_date}</p>
 *       <p>{description}</p>
 *       <a href="details.html?id={id}">View Details & Discussion</a>
 *     </article>
 *
 * Important: the href MUST be "details.html?id=<id>" (integer id from
 * the weeks table) so that details.js can read the id from the URL.
 */
function createWeekArticle(week) {
  const article = document.createElement('article');

  // <h2>{title}</h2>
  const h2 = document.createElement('h2');
  h2.textContent = week.title;

  // <p>Starts on: {start_date}</p>
  const pStartDate = document.createElement('p');
  pStartDate.textContent = `Starts on: ${week.start_date}`;

  // <p>{description}</p>
  const pDescription = document.createElement('p');
  pDescription.textContent = week.description;

  // <a href="details.html?id={id}">View Details & Discussion</a>
  const aDetails = document.createElement('a');
  aDetails.href = `details.html?id=${week.id}`;
  aDetails.textContent = 'View Details & Discussion';

  // Append elements to article
  article.appendChild(h2);
  article.appendChild(pStartDate);
  article.appendChild(pDescription);
  article.appendChild(aDetails);

  return article;
}

/**
 * Implement loadWeeks (async).
 *
 * It should:
 * 1. Use fetch() to GET data from './api/index.php'.
 *    The API returns JSON in the shape:
 *      { success: true, data: [ ...week objects ] }
 * 2. Parse the JSON response.
 * 3. Clear any existing content from the list section.
 * 4. Loop through the data array. For each week object:
 *    - Call createWeekArticle(week).
 *    - Append the returned <article> to the list section.
 */
async function loadWeeks() {
  try {
    const response = await fetch('./api/index.php');
    const result = await response.json();

    if (result.success && result.data) {
      // Clear existing content
      weekListSection.innerHTML = '';

      // Loop through data and append articles
      result.data.forEach(week => {
        const article = createWeekArticle(week);
        weekListSection.appendChild(article);
      });
    } else {
      console.error('Failed to load weeks:', result.message);
      weekListSection.innerHTML = '<p>Error loading the course breakdown.</p>';
    }
  } catch (error) {
    console.error('Error fetching data:', error);
    weekListSection.innerHTML = '<p>An error occurred while loading the course breakdown.</p>';
  }
}

// --- Initial Page Load ---
loadWeeks();