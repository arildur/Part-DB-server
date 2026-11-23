import {Controller} from "@hotwired/stimulus";

/**
 * Shows, on the page converting a part to use a meta part, only the table of the choice made. Without JavaScript all
 * tables stay visible, each under the name of its choice.
 */
export default class extends Controller {
    static targets = ["choice", "table"];

    connect() {
        this.update();
    }

    update() {
        const chosen = this.choiceTargets.find((choice) => choice.checked);
        if (!chosen) {
            return;
        }
        this.tableTargets.forEach((table) => table.classList.toggle("d-none", table.dataset.keep !== chosen.value));
    }
}
