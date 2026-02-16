export default async function getServerTime(): Promise<number> {
    try {
        const response = await fetch('/api/time');
        const data = await response.json();
        return data.time;
    } catch (error) {
        console.error('Failed to get server time:', error);
        return Math.floor(Date.now() / 1000);
    }
}
