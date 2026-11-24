/** `endpoint` is Friendly Captcha's region, `global` or `eu`. Other providers don't send it. */
export type ChallengeManifest = { provider: string; siteKey: string; action: string; endpoint?: string };
export type BrowserManifest = {
    version: 1;
    instanceId: string;
    preparation: string;
    issuedAt: number;
    expiresAt: number;
    notBefore?: number;
    honeypot: string | null;
    javascript: boolean;
    challenges: ChallengeManifest[];
};
export type Evidence = {
    version: 1;
    preparation: string;
    honeypot: Record<string, string>;
    javascript: string;
    challenges: Record<string, string>;
};
export type ChallengeDriver = {
    execute(): Promise<string>;
    reset(): void;
    destroy(): void;
};
export type DriverContext = {
    element: HTMLElement;
    manifest: ChallengeManifest;
    signal: AbortSignal;
    nonce?: string;
    onToken(token: string): void;
    onError(code?: string): void;
};
export type ChallengeDriverFactory = (context: DriverContext) => Promise<ChallengeDriver>;
export type ProtectionController = {
    ready: Promise<void>;
    prepare(): Promise<Evidence>;
    reset(): void;
    destroy(): void;
    /** Milliseconds until the preparation expires, on the server's clock. Zero once it has. */
    expiresIn(): number;
    /** Milliseconds until the minimum form age has passed, on the server's clock. */
    remainingWait(): number;
};
