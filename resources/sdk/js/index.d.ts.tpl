export const VERSION: string;
export const DEFAULT_BASE_URL: string;
export const HEADER: string;
export const DEFAULT_TOLERANCE: number;

export interface ClientOptions {
  baseUrl?: string;
  /** Milliseconds. */
  timeout?: number;
  maxRetries?: number;
  userAgent?: string;
  fetch?: typeof fetch;
}

export interface RequestOptions {
  query?: Record<string, string | number | boolean | null | undefined | Array<string | number>>;
  body?: unknown;
}

export class Response {
  status: number;
  headers: Record<string, string>;
  data: any;
  meta: any;
  message: string | null;
  readonly rateLimit: { limit: number | null; remaining: number | null };
  /** 'live' or 'sandbox'. */
  readonly mode: string | null;
}

export class UipeError extends Error {}

export class ApiError extends UipeError {
  status: number;
  code: string | null;
  errors: any;
  headers: Record<string, string>;
  body: string | null;
  readonly retryAfter: number | null;
}

export class NetworkError extends UipeError {}

export type SignatureReason =
  | 'missing_header'
  | 'malformed_header'
  | 'missing_secret'
  | 'signature_mismatch'
  | 'timestamp_too_old'
  | 'timestamp_in_future'
  | 'invalid_payload'
  | 'replayed_event';

export class SignatureError extends UipeError {
  reason: SignatureReason;
}

export interface InspectResult {
  valid: boolean;
  reason: SignatureReason | null;
  timestamp: number | null;
  ageSeconds: number | null;
  toleranceSeconds: number;
}

export interface VerifyOptions {
  tolerance?: number;
  /** Unix seconds; for tests. */
  now?: number;
}

export interface ReplayStore {
  firstSeen(eventId: string): boolean;
}

export class ReplayGuard implements ReplayStore {
  constructor(options?: { ttl?: number; maxEntries?: number });
  firstSeen(eventId: string, now?: number): boolean;
}

export function sign(secret: string | Buffer, timestamp: number, payload: string | Buffer): string;
export function buildHeader(secret: string | Buffer, payload: string | Buffer, timestamp?: number): string;
export function parseHeader(header: string): { timestamp: number; signatures: string[] } | null;
export function inspectSignature(payload: string | Buffer, header: string | null | undefined, secrets: string | string[], options?: VerifyOptions): InspectResult;
export function verifySignature(payload: string | Buffer, header: string | null | undefined, secrets: string | string[], options?: VerifyOptions): boolean;
export function constructEvent(payload: string | Buffer, header: string | null | undefined, secrets: string | string[], options?: VerifyOptions & { guard?: ReplayStore }): any;

export class Client {
  constructor(apiKey: string, options?: ClientOptions);
  apiKey: string;
  baseUrl: string;
  lastResponse: Response | null;
  request(method: string, path: string, options?: RequestOptions): Promise<any>;
  requestRaw(method: string, path: string, options?: RequestOptions): Promise<Response>;
{{OPERATIONS_DTS}}
}
