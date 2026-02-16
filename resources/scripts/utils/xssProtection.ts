/**
 * XSS Protection Utilities
 * 
 * These utilities help prevent XSS attacks by sanitizing user input
 * before displaying it in the UI.
 */

/**
 * Escapes HTML characters to prevent XSS attacks
 */
export const escapeHtml = (unsafe: string | null | undefined): string => {
    if (unsafe === null || unsafe === undefined) {
        return '';
    }
    
    return String(unsafe)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#x27;')
        .replace(/\//g, '&#x2F;');
};

/**
 * Sanitizes filename to prevent XSS and file system issues
 */
export const sanitizeFilename = (filename: string | null | undefined): string => {
    if (!filename) return '';
    
    return String(filename)
        .replace(/[<>"'&]/g, '')
        .replace(/[\/\\:*?"<>|]/g, '_')
        .replace(/[\x00-\x1f\x80-\x9f]/g, '')
        .substring(0, 255)
        .trim();
};

/**
 * Sanitizes database/table names
 */
export const sanitizeDatabaseName = (name: string | null | undefined): string => {
    if (!name) return '';
    
    return String(name)
        .replace(/[<>"'&]/g, '')
        .replace(/[^\w\-_.]/g, '_')
        .substring(0, 64)
        .trim();
};

/**
 * Sanitizes SQL query for display (not for execution!)
 */
export const sanitizeSqlForDisplay = (sql: string | null | undefined): string => {
    if (!sql) return '';
    
    return String(sql)
        .replace(/[<>"'&]/g, (match) => {
            switch (match) {
                case '<': return '&lt;';
                case '>': return '&gt;';
                case '"': return '&quot;';
                case "'": return '&#x27;';
                case '&': return '&amp;';
                default: return match;
            }
        })
        .substring(0, 10000); 
};

/**
 * Sanitizes column values for display
 */
export const sanitizeColumnValue = (value: any): string => {
    if (value === null || value === undefined) {
        return '';
    }
    
    const stringValue = String(value);
    if (stringValue.length > 1000) {
        return escapeHtml(stringValue.substring(0, 1000) + '...');
    }
    
    return escapeHtml(stringValue);
};

/**
 * Validates and sanitizes user input for forms
 */
export const sanitizeFormInput = (input: string | null | undefined): string => {
    if (!input) return '';
    
    return String(input)
        .replace(/[<>"'&]/g, '')
        .trim()
        .substring(0, 1000);
};