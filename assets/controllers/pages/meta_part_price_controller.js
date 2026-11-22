import {Controller} from "@hotwired/stimulus";

/**
 * Shows the user value of a meta part's price only while the price method is user value, and the calculated price
 * otherwise. The user value is only hidden, never cleared, so that the stored value stays until the user changes it.
 */
export default class extends Controller {
    static targets = ["method", "userValue", "calculated"];

    connect() {
        this.update();
        this.methodTarget.addEventListener("change", () => this.update());
    }

    update() {
        const userValue = this.methodTarget.value === "user_value";
        this.userValueTargets.forEach((element) => element.classList.toggle("d-none", !userValue));
        this.calculatedTargets.forEach((element) => element.classList.toggle("d-none", userValue));
    }
}
