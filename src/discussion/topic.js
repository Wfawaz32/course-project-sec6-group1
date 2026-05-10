// --- Global Data Store ---
let currentTopicId = null;
let currentReplies = [];


// --- Element Selections ---
const topicSubject = document.getElementById("topic-subject");
const opMessage = document.getElementById("op-message");
const opFooter = document.getElementById("op-footer");

const replyListContainer = document.getElementById("reply-list-container");
const replyForm = document.getElementById("reply-form");
const newReplyText = document.getElementById("new-reply");


// --- Get Topic ID from URL ---
function getTopicIdFromURL() {
    const params = new URLSearchParams(window.location.search);
    return params.get("id");
}


// --- Render Original Post ---
function renderOriginalPost(topic) {
    topicSubject.textContent = topic.subject;
    opMessage.textContent = topic.message;
    opFooter.textContent =
        "Posted by: " + topic.author + " on " + topic.created_at;
}


// --- Create Reply Article ---
function createReplyArticle(reply) {
    const article = document.createElement("article");

    article.innerHTML = `
        <p>${reply.text}</p>

        <footer>
            Posted by: ${reply.author} on ${reply.created_at}
        </footer>

        <div>
            <button class="delete-reply-btn" data-id="${reply.id}">
                Delete
            </button>
        </div>
    `;

    return article;
}


// --- Render Replies ---
function renderReplies() {
    replyListContainer.innerHTML = "";

    currentReplies.forEach(reply => {
        const article = createReplyArticle(reply);
        replyListContainer.appendChild(article);
    });
}


// --- Add Reply ---
async function handleAddReply(event) {
    event.preventDefault();

    const replyText = newReplyText.value.trim();

    if (!replyText) return;

    const response = await fetch("./api/index.php?action=reply", {
        method: "POST",
        headers: {
            "Content-Type": "application/json"
        },
        body: JSON.stringify({
            topic_id: currentTopicId,
            author: "Student",
            text: replyText
        })
    });

    const result = await response.json();

    if (result.success) {

        currentReplies.push(result.data);

        renderReplies();

        newReplyText.value = "";
    }
}


// --- Delete Reply ---
async function handleReplyListClick(event) {

    if (event.target.classList.contains("delete-reply-btn")) {

        const id = event.target.dataset.id;

        const response = await fetch(
            `./api/index.php?action=delete_reply&id=${id}`,
            { method: "DELETE" }
        );

        const result = await response.json();

        if (result.success) {

            currentReplies = currentReplies.filter(
                reply => reply.id != id
            );

            renderReplies();
        }
    }
}


// --- Initialize Page ---
async function initializePage() {

    currentTopicId = getTopicIdFromURL();

    if (!currentTopicId) {
        topicSubject.textContent = "Topic not found.";
        return;
    }

    const topicRequest = fetch(`./api/index.php?id=${currentTopicId}`);
    const repliesRequest = fetch(
        `./api/index.php?action=replies&topic_id=${currentTopicId}`
    );

    const [topicRes, repliesRes] = await Promise.all([
        topicRequest,
        repliesRequest
    ]);

    const topicData = await topicRes.json();
    const repliesData = await repliesRes.json();

    if (topicData.success) {

        renderOriginalPost(topicData.data);

        currentReplies = repliesData.success ? repliesData.data : [];

        renderReplies();

        replyForm.addEventListener("submit", handleAddReply);
        replyListContainer.addEventListener("click", handleReplyListClick);

    } else {
        topicSubject.textContent = "Topic not found.";
    }
}


// --- Start ---
initializePage();
