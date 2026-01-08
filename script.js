/**
 * Opens the modal with rich details
 * @param {string} dateStr - e.g. "Monday, October 25th"
 * @param {Array} items - Array of job objects {code, title, pay}
 * @param {string} totalAmt - e.g. "450.00"
 */
function openDay(dateStr, items, totalAmt) {
    const modal = document.getElementById("summaryModal");
    const modalTitle = document.getElementById("modalDate");
    const modalBody = document.getElementById("modalBody");

    // 1. Set the Title
    modalTitle.innerText = dateStr;

    // 2. Build the Content
    if (items.length > 0) {
        let html = '<ul class="job-list">';
        
        items.forEach(item => {
            html += `
                <li style="display:flex; justify-content:space-between; align-items:center;">
                    <div style="display:flex; flex-direction:column;">
                        <span style="font-weight:700; color:var(--text-main);">${item.title}</span>
                        <span style="font-size:0.8rem; color:var(--text-muted); background:var(--bg-body); padding:2px 6px; border-radius:4px; width:fit-content; margin-top:2px;">
                            ${item.code}
                        </span>
                    </div>
                    <div style="font-weight:700; color:var(--text-main);">${item.pay}</div>
                </li>
            `;
        });

        html += '</ul>';
        
        // Footer with Grand Total
        html += `
            <div style="margin-top:15px; padding-top:15px; border-top:2px solid var(--border); display:flex; justify-content:space-between; align-items:center;">
                <span style="font-size:0.9rem; font-weight:600; color:var(--text-muted);">TOTAL EARNED</span>
                <span style="font-size:1.4rem; font-weight:800; color:var(--success-text);">$${totalAmt}</span>
            </div>
        `;

        modalBody.innerHTML = html;
    } else {
        modalBody.innerHTML = `
            <div style="text-align:center; padding:30px; color:var(--text-muted);">
                <div style="font-size:2rem; opacity:0.3; margin-bottom:10px;">📅</div>
                No activity recorded for this date.
            </div>
        `;
    }

    // 3. Show the Modal
    modal.style.display = "block";
}

/**
 * Closes the modal
 */
function closeModal() {
    document.getElementById("summaryModal").style.display = "none";
}

/**
 * Close on click outside
 */
window.onclick = function(event) {
    const modal = document.getElementById("summaryModal");
    if (event.target == modal) {
        modal.style.display = "none";
    }
}