/// <reference types="vite/client" />

interface ImportMetaEnv {
    /** Llave pública de Reverb: el navegador la necesita para conectarse; el secreto nunca sale del servidor. */
    readonly VITE_REVERB_APP_KEY?: string;
}
