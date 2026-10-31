import { cleanup } from '@testing-library/react';
import { afterEach } from 'vitest';

// jsdom no implementa el elemento dialog: este reemplazo solo reproduce que showModal y close cambian `open`.
HTMLDialogElement.prototype.showModal = function showModal(this: HTMLDialogElement) {
    this.setAttribute('open', '');
};
HTMLDialogElement.prototype.close = function close(this: HTMLDialogElement) {
    this.removeAttribute('open');
};

afterEach(() => {
    cleanup();
});
