export interface User {
  id: number;
  name: string;
  email: string;
}

export interface Category {
  id: number;
  label: string;
  sort_order: number | null;
  created_at: string;
  updated_at: string;
}

export interface Entity {
  id: number;
  entity_label: string;
  category_id: number | null;
  category?: Category;
  date_range_type: "daily" | "weekly";
  report_type: "main" | "secondary" | null;
  sort_order: number | null;
  active: boolean;
  created_at: string;
  updated_at: string;
}

export interface Rating {
  id: number;
  label: string | null;
  created_at: string;
  updated_at: string;
}

export interface Store {
  id: number;
  store: string;
  group: number | null;
  created_at: string;
  updated_at: string;
}

export interface CameraFormNoteAttachment {
  id: number;
  camera_form_note_id: number;
  path: string;
  url: string | null;
  created_at: string;
  updated_at: string;
}

export interface CameraFormNote {
  id: number;
  camera_form_id: number;
  note: string | null;
  attachments: CameraFormNoteAttachment[];
  created_at: string;
  updated_at: string;
}

export interface CameraForm {
  id: number;
  user_id: number | null;
  entity_id: number;
  audit_id: number | null;
  rating_id: number | null;

  entity?: Entity;
  rating?: Rating;
  user?: User;

  notes: CameraFormNote[];

  created_at: string;
  updated_at: string;
}

export interface Audit {
  id: number;
  store_id: number;
  user_id: number;
  date: string;
  store?: Store;
  user?: User;
  camera_forms?: CameraForm[];
  created_at: string;
  updated_at: string;
}

export interface PaginatedData<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  links: {
    url: string | null;
    label: string;
    active: boolean;
  }[];
}
export interface StoreScoreData {
  final_total_score: number | null;
}
