import { NavFooter } from "@/components/nav-footer";
import { NavMain } from "@/components/nav-main";
import { NavUser } from "@/components/nav-user";
import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
} from "@/components/ui/sidebar";
import { dashboard } from "@/routes";
import { type NavItem } from "@/types";
import { Link, usePage } from "@inertiajs/react";
import { BookOpen, Folder, LayoutGrid, Camera, FolderOpen } from "lucide-react";
import AppLogo from "./app-logo";
import { RnDIcon } from "./rndIcon";

const mainNavItems: NavItem[] = [
  {
    title: "Dashboard",
    href: dashboard(),
    icon: LayoutGrid,
  },
  {
    title: "Camera Forms",
    href: "/camera-forms",
    icon: Camera,
  },
  {
    title: "Camera Report",
    href: "/camera-reports",
    icon: FolderOpen,
  },
  {
    title: "Stores",
    href: "/stores",
    icon: LayoutGrid,
    admin: true,
  },
  {
    title: "Entities & Categories",
    href: "/entities",
    icon: FolderOpen,
    admin: true,
  },
];

const footerNavItems: NavItem[] = [
  {
    title: "Support",
    href: "https://tasks.rdexperts.tech/support-ticket",
    icon: RnDIcon,
  },
];

export function AppSidebar() {
  const { auth } = usePage().props;
  const isAdmin = auth.user?.role === "Admin";

  // Filter nav items based on user role
  const filteredNavItems = mainNavItems.filter((item) => {
    // If item has admin flag and user is not admin, hide it
    if ((item as any).admin && !isAdmin) {
      return false;
    }
    return true;
  });

  return (
    <Sidebar collapsible="icon" variant="inset">
      <SidebarHeader>
        <SidebarMenu>
          <SidebarMenuItem>
            <SidebarMenuButton size="lg" asChild>
              <Link href={dashboard()} prefetch>
                <AppLogo />
              </Link>
            </SidebarMenuButton>
          </SidebarMenuItem>
        </SidebarMenu>
      </SidebarHeader>

      <SidebarContent>
        <NavMain items={filteredNavItems} />
      </SidebarContent>

      <SidebarFooter>
        <NavFooter items={footerNavItems} className="mt-auto" />
        <NavUser />
      </SidebarFooter>
    </Sidebar>
  );
}
