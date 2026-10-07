export type WorkerTask = 'transcribe' | 'extract';

/** A GPU machine that checks in with the app (shared Inertia prop "workers"). */
export type Worker = {
    id: number;
    name: string;
    online: boolean;
    enabled: boolean;
    backend: 'cuda' | 'mlx' | 'cpu' | null;
    gpu_name: string | null;
    vram_mb: number | null;
    capabilities: WorkerTask[];
    loaded_model: string | null;
    last_seen_at: string | null;
    current_task: WorkerTask | null;
    current_stream_id: number | null;
    current_stream_title: string | null;
};

export const workerTaskLabel = (task: WorkerTask) => ({ transcribe: 'Transcriberen', extract: 'Analyseren' }[task]);
