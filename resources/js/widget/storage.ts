// El almacenamiento del navegador puede no existir (modo privado, datos bloqueados) o lanzar al usarse. El widget sigue
// funcionando sin él: lo que no se pueda guardar vive en memoria hasta que se cierre la pestaña. La memoria solo se usa
// cuando el almacenamiento falla; si funciona, es la única fuente.
const memory = new Map<string, string>();

export function readStored(key: string): string | null {
    try {
        return window.localStorage.getItem(key);
    } catch {
        return memory.get(key) ?? null;
    }
}

export function writeStored(key: string, value: string): void {
    try {
        window.localStorage.setItem(key, value);
    } catch {
        memory.set(key, value);
    }
}

export function removeStored(key: string): void {
    try {
        window.localStorage.removeItem(key);
    } catch {
        memory.delete(key);
    }
}
