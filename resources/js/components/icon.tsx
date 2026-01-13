import { cn } from "@/lib/utils";
import type { ComponentType } from "react";

type IconComponentProps = { className?: string };

interface IconProps extends IconComponentProps {
  iconNode: ComponentType<IconComponentProps>;
}

export function Icon({ iconNode: IconComponent, className }: IconProps) {
  return <IconComponent className={cn("h-4 w-4", className)} />;
}
