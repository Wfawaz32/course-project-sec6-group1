// --- Global Data Store ---
let topics = [];

// --- Element Selections ---
const form = document.getElementById("new-topic-form");
const topicListContainer = document.getElementById("topic-list-container");

// --- Create Topic Article ---
function createTopicArticle(topic) {
    const article = document.createElement("article");

    article.innerHTML = `
        <h3>
            <a href="topic.html?id=${topic.id}">
                ${topic.subject}
            </a>
        </h3>

        <footer>
            Posted by: ${topic.author} on ${topic.created_at}
        </footer>

        <div>
            <button class="edit-btn" data-id="${topic.id}">Edit</button>
            <button class="delete-btn" data-id="${topic.id}">Delete</button>
        </div>
    `;

    return article;
}

// --- Render Topics ---
function renderTopics() {
    topicListContainer.innerHTML = "";

    topics.forEach(topic => {
        const article = createTopicArticle(topic);
        topicListContainer.appendChild(article);
    });
}

// --- Create Topic ---
async function handleCreateTopic(event) {
    event.preventDefault();

    const subject = document.getElementById("topic-subject").value;
    const message = document.getElementById("topic-message").value;

    const response = await fetch("./api/index.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/json"
        },
        body: JSON.stringify({
            subject,
            message,
            author: "Student"
        })
    });

    const result = await response.json();

    if (result.success) {

        topics.push({
            id: result.id,
            subject,
            message,
            author: "Student",
            created_at: new Date().toISOString().slice(0, 19).replace("T", " ")
        });

        renderTopics();
        form.reset();
    }
}

// --- Update Topic ---
async function handleUpdateTopic(id, fields) {

    const response = await fetch("./api/index.php", {
        method: "PUT",
        headers: {
            "Content-Type": "application/json"
        },
        body: JSON.stringify({
            id,
            subject: fields.subject,
            message: fields.message
        })
    });

    const result = await response.json();

    if (result.success) {

        const index = topics.findIndex(t => t.id == id);

        if (index !== -1) {
            topics[index].subject = fields.subject;
            topics[index].message = fields.message;
        }

        renderTopics();
    }
}

// --- Handle Clicks (Edit/Delete) ---
async function handleTopicListClick(event) {

    const id = event.target.dataset.id;

    // DELETE
    if (event.target.classList.contains("delete-btn")) {

        const response = await fetch(`./api/index.php?id=${id}`, {
            method: "DELETE"
        });

        const result = await response.json();

        if (result.success) {
            topics = topics.filter(t => t.id != id);
            renderTopics();
        }
    }

    // EDIT
    if (event.target.classList.contains("edit-btn")) {

        const topic = topics.find(t => t.id == id);

        if (topic) {
            document.getElementById("topic-subject").value = topic.subject;
            document.getElementById("topic-message").value = topic.message;

            const button = document.getElementById("create-topic");
            button.textContent = "Update Topic";
            button.dataset.editId = id;
        }
    }
}

// --- Load Page ---
async function loadAndInitialize() {

    const response = await fetch("./api/index.php");
    const result = await response.json();

    if (result.success) {
        topics = result.data;
        renderTopics();
    }

    form.addEventListener("submit", handleCreateTopic);
    topicListContainer.addEventListener("click", handleTopicListClick);
}

// --- Start ---
loadAndInitialize();
