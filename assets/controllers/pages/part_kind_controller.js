import {Controller} from "@hotwired/stimulus";

/**
 * Shows the sections of the part form that the chosen kind allows. This is only display: the server discards what the
 * submitted kind does not allow.
 */
export default class extends Controller {
    static targets = ["select"];

    static sections = {
        manufacturing: ["manufacturer", "suppliers"],
        meta: ["connections"],
    };

    connect() {
        this.update();
        this.selectTarget.addEventListener("change", () => this.update());
    }

    update() {
        const allowed = this.constructor.sections[this.selectTarget.value] ?? [];
        document.querySelectorAll("[data-part-kind-section]").forEach((element) => {
            element.classList.toggle("d-none", !allowed.includes(element.dataset.partKindSection));
        });
    }
}
