/**
 * User Management Types
 * resources/js/types/user.ts
 */

export type UserRole = 'Admin' | 'User';

export interface User {
  id: number;
  name: string;
  email: string;
  role: UserRole;
  email_verified_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface CreateUserFormData {
  name: string;
  email: string;
  role: UserRole | '';
  password: string;
  password_confirmation: string;
}

export interface UpdateUserFormData {
  name: string;
  email: string;
  role: UserRole;
  password: string;
  password_confirmation: string;
}

export interface PaginationLink {
  url: string | null;
  label: string;
  active: boolean;
}

export interface PaginatedUsers {
  data: User[];
  links: PaginationLink[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number;
  to: number;
}

export interface UserPageProps {
  auth: {
    user: User;
  };
  flash: {
    success?: string;
    error?: string;
  };
}

export interface CreateUserPageProps extends UserPageProps {}

export interface EditUserPageProps extends UserPageProps {
  user: User;
}

export interface IndexUserPageProps extends UserPageProps {
  users: PaginatedUsers;
}
